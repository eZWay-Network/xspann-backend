<?php

namespace App\Services;

use App\Models\{Artist, Notification, User, Video};
use App\Services\Billing\Billing;
use Spark\Database\QueryBuilder;
use Spark\Facades\{DB, Hash};

class Announcements
{
    public static function owned(int $id, int $userId): object
    {
        $row = DB::table('announcements')->where('id', $id)->where('user_id', $userId)->first();
        abort_unless($row !== null, 404, 'Announcement not found.');

        return $row;
    }

    public static function summaries(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $counts = DB::table('announcement_deliveries')->whereIn('announcement_id', array_column($rows, 'id'))
            ->selectRaw("announcement_id, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count, SUM(CASE WHEN status = 'suppressed' THEN 1 ELSE 0 END) AS suppressed_count")
            ->groupBy('announcement_id')->get()->keyBy('announcement_id')->all();
        $estimates = [];
        foreach ($rows as $row) {
            $estimates[$row->artist_id] ??= self::audience($row->artist_id)->count();
            $row->recipient_estimate = $estimates[$row->artist_id];
            $row->delivered_count = (int) ($counts[$row->id]->delivered_count ?? 0);
            $row->suppressed_count = (int) ($counts[$row->id]->suppressed_count ?? 0);
        }

        return $rows;
    }

    public static function deliveries(array $rows, int $viewer): array
    {
        $deliveries = $rows ? DB::table('announcement_deliveries')->where('user_id', $viewer)
            ->whereIn('announcement_id', array_column($rows, 'id'))->get()->keyBy('announcement_id')->all() : [];
        foreach ($rows as $row) {
            $row->delivery = $deliveries[$row->id] ?? null;
        }

        return $rows;
    }

    private static function pro(User $owner): Artist
    {
        $artist = Artists::owned($owner);
        abort_unless(Communications::eligible($owner->id) && $artist->moderation_status === 'approved', 403, 'Artist is not eligible.');
        app(ArtistCapabilities::class)->require($owner->id, 'fan_announcements');

        return $artist;
    }

    private static function content(User $owner, array $input): array
    {
        foreach (['title', 'body'] as $field) {
            if (isset($input[$field])) {
                // Plain text only; outbound destinations are represented by an owned video_id.
                $plain = str_replace(['。', '．', '｡'], '.', $input[$field]);
                abort_if(preg_match('~(?:[a-z][a-z0-9+.-]*://|(?:mailto|javascript|data|tel):|//[\w.-]+|www\.|(?:[\pL\pN-]+\.)+(?:[\pL]{2,}|xn--[a-z0-9-]{2,})(?=$|[^\pL\pN-])|\b(?:\d{1,3}\.){3}\d{1,3}\b|<[^>]+>)~iu', $plain), 422, 'Announcements do not support external links or markup.');
                $input[$field] = Hash::encrypt($input[$field]);
            }
        }
        if (isset($input['video_id'])) {
            abort_unless(self::publicVideos()->where('user_id', $owner->id)
                ->whereKey((int) $input['video_id'])->exists(), 422, 'Select your own public, eligible video.');
            $input['video_id'] = (int) $input['video_id'];
        }

        return $input;
    }

    public static function create(User $owner, array $input): object
    {
        return Communications::transaction(function () use ($owner, $input): object {
            $artist = self::pro($owner);
            $id = DB::table('announcements')->insert([
                ...self::content($owner, $input),
                'user_id' => $owner->id,
                'artist_id' => $artist->id,
                'created_at' => Billing::timestamp(),
                'updated_at' => Billing::timestamp(),
            ]);

            return self::owned($id, $owner->id);
        });
    }

    public static function edit(User $owner, int $id, array $input): object
    {
        return Communications::transaction(function () use ($owner, $id, $input): object {
            self::pro($owner);
            $row = self::owned($id, $owner->id);
            abort_unless($row->status === 'draft' && (int) $row->version === (int) $input['version'], 409, 'Announcement is not an editable current draft.');
            unset($input['version']);
            DB::table('announcements')->where('id', $id)->update([
                ...self::content($owner, $input),
                'version' => $row->version + 1,
                'updated_at' => Billing::timestamp(),
            ]);

            return self::owned($id, $owner->id);
        });
    }

    public static function delete(int $id, int $userId): void
    {
        Communications::transaction(function () use ($id, $userId): void {
            $row = self::owned($id, $userId);
            abort_unless($row->status === 'draft', 409, 'An accepted announcement cannot be deleted.');
            DB::table('announcements')->where('id', $id)->delete();
        });
    }

    /** All filters are SQL-side; snapshots never silently truncate an audience. */
    public static function audience(int $artistId): QueryBuilder
    {
        return DB::table('artist_update_subscriptions as s')
            ->join('artists as a', 'a.id', '=', 's.artist_id')
            ->join('users as owner', 'owner.id', '=', 'a.user_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('communication_preferences as p', 'p.user_id', '=', 's.user_id')
            ->selectRaw('s.user_id, s.revision, COALESCE(p.updates_revision, 1) AS updates_revision')
            ->where('s.artist_id', $artistId)->where('s.subscribed', true)
            ->where('s.consent_version', config('communications.consent_version'))
            ->where('a.moderation_status', 'approved')->where('owner.status', 'active')
            ->whereNotNull('owner.email_verified_at')->whereNotNull('owner.onboarded_at')
            ->where('u.status', 'active')->whereNotNull('u.email_verified_at')->whereNotNull('u.onboarded_at')
            ->whereColumn('s.user_id', '!=', 'a.user_id')
            ->whereRaw('COALESCE(p.artist_updates_enabled, 1) = 1')
            ->whereNotExists(DB::table('blocks as b')->select('b.id')->where(fn($q) => $q
                ->where(fn($q) => $q->whereColumn('b.blocker_id', 'a.user_id')->whereColumn('b.blocked_id', 's.user_id'))
                ->orWhere(fn($q) => $q->whereColumn('b.blocker_id', 's.user_id')->whereColumn('b.blocked_id', 'a.user_id'))));
    }

    public static function send(User $owner, int $id, int $version, string $key): array
    {
        abort_unless(preg_match('/^[\x21-\x7e]{8,200}$/D', $key), 422, 'Supply an Idempotency-Key of 8–200 visible ASCII characters.');

        return Billing::locked($owner->id, fn() => Communications::transaction(function () use ($owner, $id, $version, $key): array {
            // Billing reconciliation/refunds take this same database row lock. A cache lease alone can expire.
            User::increment('id', 0, where: ['id' => $owner->id]);
            $hash = hash('sha256', json_encode([$id, $version]));
            $keyHash = hash('sha256', $key);
            $prior = DB::table('communication_idempotency')->where('user_id', $owner->id)->where('operation', 'announcement.send')->where('key_hash', $keyHash)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $hash), 409, 'Idempotency key was used with different input.');

                return json_decode($prior->result, true, flags: JSON_THROW_ON_ERROR);
            }
            $artist = self::pro($owner);
            $row = self::owned($id, $owner->id);
            abort_unless($row->status === 'draft' && (int) $row->version === $version, 409, 'Announcement has changed or was already accepted.');
            abort_unless(self::eligibleContent($row), 422, 'Announcement content is not eligible.');
            $period = Billing::access($owner->id);
            abort_unless($period && $period->plan_code === 'artist_pro', 403, 'A paid Pro service period is required.');
            $limits = app(ArtistCapabilities::class)->forUser($owner->id);
            $recipients = self::audience($artist->id)->limit($limits['announcement_recipients'] + 1)->get();
            abort_if(count($recipients) > $limits['announcement_recipients'], 422, 'Audience exceeds the announcement recipient limit.');
            abort_if(DB::table('announcements')->where('paid_period_id', $period->id)->whereNotNull('accepted_at')->count() >= $limits['fan_announcements'], 429, 'Announcement quota exhausted for this paid period.');
            DB::table('announcements')->where('id', $id)->update([
                'status' => 'queued',
                'version' => $version + 1,
                'paid_period_id' => $period->id,
                'recipient_count' => count($recipients),
                'accepted_at' => Billing::timestamp(),
                'updated_at' => Billing::timestamp(),
            ]);
            foreach ($recipients as $recipient) {
                DB::table('announcement_deliveries')->insert([
                    'announcement_id' => $id,
                    'user_id' => $recipient->user_id,
                    'consent_revision' => $recipient->revision,
                    'updates_revision' => $recipient->updates_revision,
                ]);
            }
            $result = ['id' => $id, 'status' => 'queued', 'version' => $version + 1, 'recipient_count' => count($recipients)];
            DB::table('communication_idempotency')->insert([
                'user_id' => $owner->id,
                'operation' => 'announcement.send',
                'key_hash' => $keyHash,
                'request_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => Billing::timestamp(),
            ]);

            return $result;
        }));
    }

    private static function publicVideos(): QueryBuilder
    {
        return Video::published()->visibleTo(null)->where('visibility', 'public')
            ->whereNotNull('rights_confirmed_at')->where('rights_version', config('policies.rights_version'));
    }

    public static function eligibleContent(object $row): bool
    {
        return $row->moderation_status === 'approved' && Communications::eligible((int) $row->user_id)
            && Artist::whereKey($row->artist_id)->where('moderation_status', 'approved')->exists()
            && (!$row->video_id || self::publicVideos()->whereKey($row->video_id)->where('user_id', $row->user_id)->exists());
    }

    public static function inboxQuery(User $viewer): QueryBuilder
    {
        return DB::table('announcements')->where('moderation_status', 'approved')
            ->whereIn('artist_id', Artist::visibleTo($viewer)->select('artists.id'))
            ->whereIn('user_id', DB::table('users')->select('id')->where('status', 'active')->whereNotNull('email_verified_at')->whereNotNull('onboarded_at'))
            ->whereIn('id', DB::table('announcement_deliveries')->select('announcement_id')->where('user_id', $viewer->id)->where('status', 'delivered'))
            ->where(fn($q) => $q->whereNull('video_id')->orWhereIn('video_id', self::publicVideos()->select('videos.id')));
    }

    public static function visible(object $row, int $viewer): bool
    {
        return self::eligibleContent($row) && !Communications::blocked((int) $row->user_id, $viewer);
    }

    public static function delivered(int $id, int $viewer, bool $report = false): object
    {
        $row = DB::table('announcements')->where('id', $id)->first();
        $delivery = DB::table('announcement_deliveries')->where('announcement_id', $id)->where('user_id', $viewer)->where('status', 'delivered')->first();
        abort_unless($row && $delivery && ($report || self::visible($row, $viewer)), 404, 'Announcement not found.');

        return $row;
    }

    /** Each recipient is a separate committed unit, with durable status for retry/restart. */
    public static function deliver(int $id): void
    {
        $ownerId = DB::table('announcements')->where('id', $id)->value('user_id');
        if (!$ownerId) {
            return;
        }

        foreach (DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'pending')->orderBy('id')->limit(1000)->pluck('id') as $deliveryId) {
            Communications::transaction(function () use ($id, $deliveryId, $ownerId): void {
                // Acquire before reads so a concurrent refund cannot revoke the checked period before commit.
                User::increment('id', 0, where: ['id' => $ownerId]);
                $delivery = DB::table('announcement_deliveries')->where('id', $deliveryId)->where('status', 'pending')->first();
                $row = DB::table('announcements')->where('id', $id)->first();
                if (!$delivery || !$row || $row->status !== 'queued') {
                    return;
                }
                $consent = self::audience($row->artist_id)->where('s.user_id', $delivery->user_id)->first();
                // Expiry, refund or a new period cannot keep an old campaign running.
                $period = Billing::access($row->user_id);
                if (!$consent || (int) $consent->revision !== (int) $delivery->consent_revision
                    || (int) $consent->updates_revision !== (int) $delivery->updates_revision
                    || !self::eligibleContent($row) || !$period || (int) $period->id !== (int) $row->paid_period_id || $period->plan_code !== 'artist_pro') {
                    DB::table('announcement_deliveries')->where('id', $deliveryId)->update(['status' => 'suppressed']);
                    return;
                }
                $notification = Notification::create([
                    'user_id' => $delivery->user_id,
                    'type' => 'artist_announcement',
                    'data' => ['announcement_id' => $id, 'artist_id' => (int) $row->artist_id, 'message' => 'An artist you subscribed to has posted an update.'],
                ]);
                DB::table('announcement_deliveries')->where('id', $deliveryId)->update([
                    'status' => 'delivered',
                    'notification_id' => $notification->id,
                    'delivered_at' => Billing::timestamp(),
                ]);
            });
        }
        Communications::transaction(function () use ($id): void {
            if (!DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'pending')->exists()) {
                DB::table('announcements')->where('id', $id)->where('status', 'queued')->update(['status' => 'completed', 'updated_at' => Billing::timestamp()]);
            }
        });
    }
}
