<?php

namespace App\Services;

use App\Models\{Artist, Block, User};
use App\Services\Billing\Billing;
use Spark\Database\QueryBuilder;
use Spark\Facades\DB;
use Spark\Foundation\Exceptions\ValidationException;
use Spark\Http\Request;

class Communications
{
    public const DEFAULTS = [
        'allow_direct_messages' => true,
        'artist_updates_enabled' => true,
        'message_alerts_enabled' => true,
        'mute_new_conversations' => false,
    ];

    public static function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            self::lock();

            return $callback();
        });
    }

    /** Must precede reads in a write transaction; also takes SQLite's writer lock. */
    public static function lock(): void
    {
        if (DB::table('communication_locks')->where('id', 1)->update(['revision' => DB::raw('revision + 1')]) !== 1) {
            throw new \RuntimeException('Communication database mutex is missing.');
        }
    }

    public static function eligible(int $id): bool
    {
        return DB::table('users')->where('id', $id)->where('status', 'active')
            ->whereNotNull('email_verified_at')->whereNotNull('onboarded_at')->exists();
    }

    public static function blocked(int $a, int $b): bool
    {
        return Block::where(fn($q) => $q->where('blocker_id', $a)->where('blocked_id', $b))
            ->orWhere(fn($q) => $q->where('blocker_id', $b)->where('blocked_id', $a))->exists();
    }

    public static function preferences(int $id): array
    {
        $row = DB::table('communication_preferences')->where('user_id', $id)->first();
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $result[$key] = $row ? (bool) $row->$key : $default;
        }

        return [...$result, 'updates_revision' => (int) ($row?->updates_revision ?? 1)];
    }

    public static function updatePreferences(int $id, array $input): array
    {
        return self::transaction(function () use ($id, $input): array {
            $before = self::preferences($id);
            $after = [...$before, ...$input];
            if ($after['artist_updates_enabled'] !== $before['artist_updates_enabled']) {
                $after['updates_revision']++;
                self::consentHistory($id, null, 'global_artist_updates', $after['artist_updates_enabled'], $after['updates_revision']);
            }
            DB::table('communication_preferences')->upsert(['user_id' => $id, ...$after], ['user_id'], array_keys($after));

            return $after;
        });
    }

    public static function subscription(int $userId, int $artistId): array
    {
        $row = DB::table('artist_update_subscriptions')->where('artist_id', $artistId)->where('user_id', $userId)->first();

        return [
            'subscribed' => (bool) ($row?->subscribed ?? false),
            'consent_version' => $row?->consent_version,
            'current_consent_version' => config('communications.consent_version'),
            'revision' => (int) ($row?->revision ?? 0),
            'channel' => 'in_app',
            'artist_updates_enabled' => self::preferences($userId)['artist_updates_enabled'],
        ];
    }

    public static function subscribe(User $user, int $artistId, bool $subscribed, ?string $version): array
    {
        return self::transaction(function () use ($user, $artistId, $subscribed, $version): array {
            // Opt-out remains possible after a block or artist restriction.
            if ($subscribed) {
                $artist = Artist::visibleTo($user)->findOrFail($artistId);
                abort_if($artist->user_id === $user->id, 422, 'An artist cannot subscribe to their own updates.');
                abort_unless($version === config('communications.consent_version'), 422, 'Accept the current artist update consent version.');
            } else {
                abort_unless(DB::table('artists')->where('id', $artistId)->exists(), 404, 'Artist not found.');
            }

            $before = self::subscription($user->id, $artistId);
            if ($before['subscribed'] !== $subscribed || ($subscribed && $before['consent_version'] !== $version)) {
                $revision = $before['revision'] + 1;
                DB::table('artist_update_subscriptions')->upsert([
                    'artist_id' => $artistId,
                    'user_id' => $user->id,
                    'subscribed' => $subscribed,
                    'consent_version' => $subscribed ? $version : $before['consent_version'],
                    'revision' => $revision,
                    'updated_at' => Billing::timestamp(),
                ], ['artist_id', 'user_id'], ['subscribed', 'consent_version', 'revision', 'updated_at']);
                self::consentHistory($user->id, $artistId, 'artist_updates', $subscribed, $revision, $version);
            }

            return self::subscription($user->id, $artistId);
        });
    }

    private static function consentHistory(int $userId, ?int $artistId, string $purpose, bool $enabled, int $revision, ?string $version = null): void
    {
        DB::table('communication_consent_history')->insert([
            'user_id' => $userId,
            'artist_id' => $artistId,
            'purpose' => $purpose,
            'enabled' => $enabled,
            'revision' => $revision,
            'consent_version' => $version,
            'created_at' => Billing::timestamp(),
        ]);
    }

    /** Reject unknown inputs, loose booleans, blank text and invalid Unicode before persistence. */
    public static function input(Request $request, array $rules, array $booleans = [], array $text = []): array
    {
        $errors = [];
        foreach (array_diff(array_keys($request->all()), array_keys($rules)) as $field) {
            $errors[$field] = ['This field is not writable.'];
        }
        foreach ($booleans as $field) {
            if ($request->has($field) && !is_bool($request->input($field))) {
                $errors[$field] = ['Supply an explicit JSON boolean.'];
            }
        }
        foreach ($text as $field) {
            $value = $request->input($field);
            if ($request->has($field) && (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/^\s*$/u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))) {
                $errors[$field] = ['Supply nonblank UTF-8 text without control characters.'];
            }
            // Spark's generic max rule counts bytes; this API promises Unicode character limits.
            foreach ($rules[$field] as $index => $rule) {
                if (is_string($rule) && preg_match('/^max:(\d+)$/D', $rule, $match)) {
                    if (is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') > (int) $match[1]) {
                        $errors[$field] = ["This field must not exceed {$match[1]} characters."];
                    }
                    unset($rules[$field][$index]);
                }
            }
            $rules[$field] = array_values($rules[$field]);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $request->validate($rules)->all();
    }

    /** Stable creation ordering; cursors are validated data, never SQL fragments. */
    public static function page(QueryBuilder $query, Request $request, callable $map, ?callable $hydrate = null): array
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);
        $limit = (int) ($input->limit ?? 20);
        if ($input->has('cursor')) {
            $cursor = json_decode(base64_decode($input->cursor, true) ?: '', true);
            abort_unless(is_array($cursor) && count($cursor) === 2 && is_string($cursor[0] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $cursor[0])
                && is_int($cursor[1] ?? null) && $cursor[1] > 0, 422, 'Invalid cursor.');
            $query->where(fn($q) => $q->where('created_at', '<', $cursor[0])
                ->orWhere(fn($q) => $q->where('created_at', $cursor[0])->where('id', '<', $cursor[1])));
        }
        $rows = $query->orderByRaw('created_at DESC, id DESC')->limit($limit + 1)->get()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows ? end($rows) : null;
        if ($hydrate) {
            $rows = $hydrate($rows);
        }

        return [
            'data' => array_map($map, $rows),
            'meta' => ['limit' => $limit, 'next_cursor' => $more ? base64_encode(json_encode([$last->created_at, (int) $last->id])) : null],
        ];
    }
}
