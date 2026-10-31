<?php

namespace App\Services\Promotions;

use App\Http\Resources\VideoResource;
use App\Models\{Artist, User, Video};
use App\Services\VideoActions;
use App\Services\Billing\Billing;
use Spark\Facades\{DB, Hash};

class Delivery
{
    public static function placements(?User $viewer): array
    {
        if (!Availability::healthy()) {
            return [];
        }
        $rows = Promotion::table('boosts')->where('status', 'active')->where('starts_at', '<=', Billing::timestamp())
            ->where('ends_at', '>', Billing::timestamp())->orderBy('id')->limit((int) config('promotions.capacity'))->get();
        $eligible = [];
        foreach ($rows as $row) {
            if (!Invalidations::cutoff($row) && Promotion::creative($row->artist_id, $row->video_id, $viewer) && Credits::funded($row)
                && $row->rights_version === config('promotions.rights_version')) {
                $eligible[] = $row;
            }
        }
        if (!$eligible) {
            return [];
        }
        $videos = Video::published()->visibleTo($viewer)->where('visibility', 'public')->whereIn('id', array_column($eligible, 'video_id'))
            ->withApiData($viewer)->get()->keyBy('id');
        $artists = Artist::whereIn('id', array_column($eligible, 'artist_id'))->get()->keyBy('id');
        $result = [];
        foreach ($eligible as $row) {
            $video = $videos[$row->video_id] ?? null;
            $artist = $artists[$row->artist_id] ?? null;
            if (!$video || !$artist) {
                continue;
            }
            $result[] = [
                'boost_id' => (int) $row->id, 'surface' => $row->surface, 'disclosure' => 'Sponsored',
                'sponsor' => ['artist_id' => $artist->id, 'user_id' => $artist->user_id, 'name' => $artist->stage_name, 'slug' => $artist->slug],
                'video' => VideoResource::make($video)->resolve(), 'expires_at' => Billing::date($row->ends_at),
                'event_token' => Hash::encrypt(json_encode([
                    'purpose' => 'promotion_event', 'environment' => $row->environment, 'boost_id' => $row->id,
                    'version' => $row->version, 'viewer' => $viewer?->id, 'nonce' => bin2hex(random_bytes(16)),
                    'expires' => min(time() + 300, Promotion::seconds($row->ends_at)),
                ], JSON_THROW_ON_ERROR)),
            ];
        }
        // Equal rotation of the same reserved set, separate from all organic/native feed ranking.
        $offset = intdiv(time(), 60) % count($result ?: [null]);

        return [...array_slice($result, $offset), ...array_slice($result, 0, $offset)];
    }

    public static function event(array $input, ?User $viewer): array
    {
        VideoActions::authorizeOptionalActor();
        abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $input['event_id']), 422, 'Supply an event UUID.');
        try {
            $token = json_decode(Hash::decrypt($input['token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'Invalid promotion event token.');
        }
        abort_unless(is_array($token) && ($token['purpose'] ?? null) === 'promotion_event'
            && ($token['environment'] ?? null) === config('billing.environment')
            && ($token['viewer'] ?? null) === $viewer?->id
            && is_int($token['expires'] ?? null) && is_int($token['boost_id'] ?? null), 422, 'Invalid promotion event token.');
        $id = strtolower($input['event_id']);
        $digest = Promotion::hash($token);

        return Promotion::transaction(function () use ($input, $viewer, $token, $id, $digest): array {
            $old = DB::table('promotion_events')->where('event_id', $id)->first();
            if ($old) {
                abort_unless($old->token_hash === $digest && $old->kind === $input['type'], 409, 'Event UUID already has different content.');

                return ['accepted' => true, 'duplicate' => true, 'billable' => false];
            }
            abort_if($token['expires'] <= time(), 410, 'Promotion token expired.');
            $boost = Boosts::find($token['boost_id']);
            abort_unless($boost->status === 'active' && (int) $boost->version === $token['version']
                && $boost->ends_at > Billing::timestamp() && Promotion::creative($boost->artist_id, $boost->video_id, $viewer)
                && !Invalidations::cutoff($boost) && Credits::funded($boost) && Availability::healthy(), 410, 'Placement is no longer available.');
            if (DB::table('promotion_events')->where('token_hash', $digest)->where('kind', $input['type'])->exists()) {
                return ['accepted' => true, 'duplicate' => true, 'billable' => false];
            }
            DB::table('promotion_events')->insert(['boost_id' => $boost->id, 'event_id' => $id,
                'token_hash' => $digest, 'kind' => $input['type'], 'created_at' => Billing::timestamp()]);

            return ['accepted' => true, 'duplicate' => false, 'billable' => false];
        });
    }
}
