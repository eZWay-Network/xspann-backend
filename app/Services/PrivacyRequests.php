<?php

namespace App\Services;

use App\Jobs\{DeleteAudio, DeleteUnusedMedia, DeleteVideo, ProcessPrivacyRequest};
use App\Models\{Artist, Audio, Notification, User, Video};
use Spark\Facades\{Cache, DB, Hash};
use function in_array;

class PrivacyRequests
{
    public static function request(User $user, string $kind, string $proof): object
    {
        $record = Cache::lock("accounts.privacy.$user->id", 30)->block(5, fn() => DB::transaction(function () use ($user, $kind, $proof): object {
            AccountSecurity::consume($proof, $kind === 'deletion' ? 'delete_account' : 'export_data', $user->id, AccountSecurity::session());

            return self::create($user, $kind);
        }));

        self::dispatch((int) $record->id);

        return $record;
    }

    public static function confirmDeletion(string $token): object
    {
        $record = DB::transaction(function () use ($token): object {
            $userId = AccountSecurity::consume($token, 'email_deletion');

            return self::create(User::findOrFail($userId), 'deletion');
        });
        self::dispatch((int) $record->id);

        return $record;
    }

    private static function create(User $user, string $kind): object
    {
        Communications::lock();

        // A real row write also serializes this path on SQLite.
        DB::table('users')->where('id', $user->id)->update(['updated_at' => now()->toDateTimeString()]);
        $user->refresh();

        $existing = DB::table('privacy_requests')->where('active_key', "$kind:$user->id")->first();
        if ($existing) {
            return $existing;
        }

        abort_unless(
            in_array($user->status, ['active', 'suspended'], true),
            403,
            'This account is not available.'
        );

        $id = DB::table('privacy_requests')->insert([
            'reference' => bin2hex(random_bytes(20)),
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => 'pending',
            'active_key' => "$kind:$user->id",
            'manifest' => $kind === 'deletion' ? Hash::encrypt(json_encode(array_values(array_filter([
                $user->avatar,
                Artist::where('user_id', $user->id)->first()?->image,
            ])), JSON_THROW_ON_ERROR)) : null,
            'expires_at' => $kind === 'export' ? now()->addHours(config('policies.export_hours', 24))->toDateTimeString() : null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        if ($kind === 'deletion') {
            $user->update(['status' => 'deleting']);
            \App\Services\Promotions\Invalidations::record('user', $user->id);
            Realtime::retire($user->id);
            DB::table('billing_subscriptions')->where('user_id', $user->id)->update(['deletion_requested_at' => \App\Services\Billing\Billing::timestamp()]);
            foreach (['jwt_access_tokens', 'staff_sessions', 'account_action_tokens'] as $table) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
            DB::table('staff_accounts')->where('user_id', $user->id)->update(['enabled' => false]);
        }

        AuditTrail::record("privacy.$kind.requested", ['request_id' => $id], $user->id);

        return DB::table('privacy_requests')->where('id', $id)->first();
    }

    public static function dispatch(int $id): void
    {
        try {
            ProcessPrivacyRequest::dispatch($id)->onQueue('privacy')->send();
        } catch (\Throwable) {
            // The durable pending row is recovered by the minute-level maintenance job.
        }
    }

    public static function process(int $id): void
    {
        Cache::lock("privacy.process.$id", 3600)->block(5, function () use ($id): void {
            $record = DB::table('privacy_requests')->where('id', $id)->first();
            if (!$record || !in_array($record->status, ['pending', 'processing', 'failed'], true) || $record->attempts >= 5) {
                return;
            }
            if ($record->kind === 'export' && $record->expires_at && strtotime($record->expires_at) <= time()) {
                self::removeExport($id);
                return;
            }

            DB::table('privacy_requests')->where('id', $id)->update([
                'status' => 'processing',
                'attempts' => $record->attempts + 1,
                'error_code' => null,
                'updated_at' => now()->toDateTimeString(),
            ]);

            try {
                if ($record->kind === 'deletion') {
                    self::deleteAccount($record);
                } else {
                    self::exportAccount($record);
                }
            } catch (\Throwable $exception) {
                DB::table('privacy_requests')->where('id', $id)->where('status', 'processing')
                    ->update(['status' => 'failed', 'error_code' => 'processing_failed', 'updated_at' => now()->toDateTimeString()]);
                throw new \RuntimeException('Privacy request processing failed; use the request reference for investigation.', 0, $exception);
            }
        });
    }

    private static function deleteAccount(object $record): void
    {
        $user = User::find($record->user_id);
        if (!$user) {
            self::complete($record->id);
            return;
        }
        if ($user->status !== 'deleting') {
            throw new \RuntimeException('Account is not pending deletion.');
        }

        app(\App\Services\Billing\SubscriptionActions::class)->deleteAccount($user->id);
        \App\Services\Promotions\Privacy::deleting($user->id);

        // Keep rows and their paths until file cleanup succeeds, so retries can resume safely.
        foreach (Video::where('user_id', $user->id)->get() as $video) {
            $video->update(['status' => Video::STATUS_DELETED]);
            (new DeleteVideo($video->id))->handle();
        }
        foreach (Audio::where('user_id', $user->id)->get() as $audio) {
            $audio->update(['status' => 'deleted']);
            (new DeleteAudio($audio->id))->handle();
        }

        $user->update(['avatar' => null]);
        Artist::where('user_id', $user->id)->update(['image' => null]);
        (new DeleteUnusedMedia($user->id, $record->manifest ? json_decode(Hash::decrypt($record->manifest), true, flags: JSON_THROW_ON_ERROR) : []))->handle();
        foreach (DB::table('pending_uploads')->where('user_id', $user->id)->get() as $upload) {
            (new DeleteUnusedMedia($user->id, [$upload->disk . ':' . $upload->path]))->handle();
            // Retain the ledger until normal expiry: a previously signed PUT can arrive late.
        }

        app(ChunkUploads::class)->deleteForUser($user->id);

        foreach (DB::table('privacy_requests')->where('user_id', $user->id)->where('kind', 'export')->get() as $export) {
            self::removeExport($export->id);
        }

        DB::transaction(function () use ($user, $record): void {
            Communications::lock();
            CommunicationPrivacy::deleting($user->id);

            // Remove personal activity snapshots retained in other users' notification lists.
            $lastId = 0;
            do {
                $notifications = Notification::where('id', '>', $lastId)->orderBy('id')->limit(200)->get();
                foreach ($notifications as $notification) {
                    $lastId = $notification->id;
                    if (($notification->data['actor']['id'] ?? null) === $user->id) {
                        $notification->remove();
                    }
                }
            } while (count($notifications) === 200);

            DB::table('video_views')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (!$user->remove()) {
                throw new \RuntimeException('Account deletion failed.');
            }

            // Cascading deletion includes descendants of the user's comments. Reconcile totals.
            $lastId = 0;
            do {
                $videos = Video::where('id', '>', $lastId)->orderBy('id')->limit(200)->get();
                foreach ($videos as $video) {
                    $lastId = $video->id;
                    VideoActions::transaction($video->id, function (Video $video): void {
                        $video->update([
                            'likes_count' => $video->likes()->count(),
                            'comments_count' => $video->comments()->count(),
                            'saves_count' => $video->saves()->count(),
                            'shares_count' => $video->shares()->count(),
                            'views_count' => $video->views()->count(),
                        ]);
                    });
                }
            } while (count($videos) === 200);

            self::complete($record->id);
            AuditTrail::record('privacy.deletion.completed', ['request_id' => $record->id], null, 'system');
        });
    }

    private static function exportAccount(object $record): void
    {
        $user = User::find($record->user_id);
        if (!$user || $user->status === 'deleting') {
            self::removeExport($record->id);
            return;
        }

        $data = [
            'generated_at' => now()->toDateTimeString(),
            'account' => array_intersect_key($user->toArray(), array_flip(['id', 'name', 'username', 'email', 'avatar', 'bio', 'status', 'created_at', 'onboarded_at'])),
            'date_of_birth' => $user->date_of_birth,
        ];
        foreach ([
            'auth_identities' => ['user_id', ['provider', 'provider_id', 'created_at']],
            'comments_reacts' => ['user_id', ['comment_id', 'reaction_type', 'created_at']],
            'moderation_decisions' => ['subject_user_id', ['id', 'action', 'reason', 'active', 'created_at']],
            'policy_acceptances' => ['user_id', ['policy', 'version', 'source', 'accepted_at']],
            'videos' => ['user_id', ['id', 'caption', 'audio_id', 'visibility', 'status', 'processing_state', 'publication_state', 'publish_at', 'published_at', 'moderation_status', 'rights_version', 'reuse_content', 'created_at']],
            'audios' => ['user_id', ['id', 'title', 'origin', 'status', 'reuse_allowed', 'rights_version', 'created_at']],
            'comments' => ['user_id', ['id', 'video_id', 'parent_id', 'body', 'created_at']],
            'likes' => ['user_id', ['video_id', 'created_at']],
            'saves' => ['user_id', ['video_id', 'created_at']],
            'audio_saves' => ['user_id', ['audio_id', 'created_at']],
            'shares' => ['user_id', ['video_id', 'channel', 'created_at']],
            'video_views' => ['user_id', ['video_id', 'created_at']],
            'follows' => ['follower_id', ['following_id', 'created_at']],
            'blocks' => ['blocker_id', ['blocked_id', 'created_at']],
            'reports' => ['user_id', ['id', 'target_type', 'target_id', 'reason', 'details', 'status', 'created_at']],
            'moderation_appeals' => ['user_id', ['id', 'decision_id', 'explanation', 'status', 'resolution', 'created_at']],
        ] as $table => [$owner, $columns]) {
            $data[$table] = DB::table($table)->select($columns)->where($owner, $user->id)->get()->toArray();
        }

        $artist = Artist::where('user_id', $user->id)->first();
        $data['artist'] = null;

        if ($artist) {
            $data['artist'] = array_intersect_key($artist->toArray(), array_flip([
                'id',
                'stage_name',
                'slug',
                'bio',
                'location',
                'image',
                'featured_video_id',
                'listed',
                'moderation_status',
                'data_since',
                'created_at',
                'updated_at',
            ]));

            $data['artist']['genre_ids'] = DB::table('artist_genre_assignments')->where('artist_id', $artist->id)->pluck('genre_id');
            $data['artist']['links'] = DB::table('artist_links')->select(['label', 'type', 'url', 'position'])
                ->where('artist_id', $artist->id)->orderBy('position')->get()->toArray();
            $data['artist']['playlists'] = DB::table('artist_playlists')
                ->select(['id', 'title', 'description', 'genre_id', 'category', 'visibility', 'moderation_status', 'version', 'created_at', 'updated_at'])
                ->where('artist_id', $artist->id)->get()->toArray();
            $data['artist']['playlist_items'] = DB::table('artist_playlist_items')->select(['playlist_id', 'video_id', 'position'])
                ->whereIn('playlist_id', DB::table('artist_playlists')->select('id')->where('artist_id', $artist->id))
                ->orderByRaw('playlist_id ASC, position ASC')->get()->toArray();
            $data['artist']['daily_statistics'] = DB::table('artist_daily_statistics')
                ->select(['date', ...ArtistStatistics::METRICS, 'collected_at'])
                ->where('artist_id', $artist->id)->orderBy('date')->get()->toArray();
        }

        $data['communications'] = CommunicationPrivacy::export($user->id);
        $data['promotions'] = \App\Services\Promotions\Privacy::export($user->id);

        $data['subscription'] = \App\Services\Billing\Billing::subscriptionData($user->id);
        $data['billing_invoices'] = DB::table('billing_invoices')->select(['invoice_id', 'status', 'amount_paid', 'amount_due', 'currency', 'issued_at'])
            ->whereIn('subscription_id', DB::table('billing_subscriptions')->select('id')->where('user_id', $user->id))->get()->toArray();

        $notifications = Notification::select(['id', 'type', 'data', 'read_at', 'created_at'])->where('user_id', $user->id)->get();
        SocialNotifications::redact($notifications->all(), $user);
        $data['notifications'] = $notifications->toArray();

        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (!storage('local')->put(self::exportPath($record->id), Hash::encrypt($contents))) {
            throw new \RuntimeException('Export storage failed.');
        }

        // Recheck after generation: a concurrent deletion must not create a usable export.
        DB::transaction(function () use ($user, $record): void {
            $current = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$current || $current->status === 'deleting') {
                self::removeExport($record->id);
                return;
            }
            $updated = DB::table('privacy_requests')->where('id', $record->id)->where('status', 'processing')->update([
                'status' => 'ready',
                'active_key' => null,
                'completed_at' => now()->toDateTimeString(),
                'expires_at' => now()->addHours(config('policies.export_hours', 24))->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
            if (!$updated) {
                self::removeExport($record->id);
            }
        });
    }

    private static function complete(int $id): void
    {
        DB::table('privacy_requests')->where('id', $id)->update([
            'status' => 'completed',
            'active_key' => null,
            'manifest' => null,
            'completed_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            'error_code' => null,
        ]);
    }

    public static function exportPath(int $id): string
    {
        return "privacy/exports/$id.json.enc";
    }

    public static function removeExport(int $id): void
    {
        if (!storage('local')->delete(self::exportPath($id))) {
            throw new \RuntimeException('Export cleanup failed.');
        }
        DB::table('privacy_requests')->where('id', $id)->update(['status' => 'expired', 'active_key' => null, 'updated_at' => now()->toDateTimeString()]);
    }

    public static function signature(int $id, int $owner, int $expires): string
    {
        return hash_hmac('sha256', "privacy-export:$id:$owner:$expires", config('app.key'));
    }
}
