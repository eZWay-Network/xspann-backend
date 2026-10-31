<?php

namespace App\Services;

use App\Models\{Artist, Audio, Notification, User, Video};
use App\Services\Billing\Billing;
use DateTimeImmutable;
use DateTimeZone;
use Spark\Facades\DB;
use Spark\Foundation\Exceptions\ValidationException;

class Releases
{
    public static function transaction(int $id, callable $callback): mixed
    {
        $video = Video::findOrFail($id);

        return DB::transaction(function () use ($video, $callback): mixed {
            // Account first: serializes publication with account deletion and media attachment.
            User::increment('id', 0, where: ['id' => $video->user_id]);
            Video::increment('version', 0, where: ['id' => $video->id]);

            return $callback(Video::findOrFail($video->id));
        });
    }

    public static function owned(User $user, int $id): Video
    {
        return Video::where('user_id', $user->id)->where('status', '!=', Video::STATUS_DELETED)->findOrFail($id);
    }

    public static function eligible(Video $video): bool
    {
        $user = $video->user()->first();
        if (!$user || $user->status !== 'active' || !$user->hasVerifiedEmail() || !$user->onboarded_at
            || !$video->rights_confirmed_at || $video->rights_version !== config('policies.rights_version')
            || $video->moderation_status !== 'approved'
            || in_array($video->status, [Video::STATUS_DELETED, Video::STATUS_SOURCE_UNAVAILABLE], true)) {
            return false;
        }
        if (!$video->audio_id) {
            return true;
        }
        // An extracted original remains private with its draft; it is not a selected library sound.
        $audio = Audio::find($video->audio_id);
        if ($audio && $audio->source_video_id === $video->id) {
            return $audio->status === 'ready' && $audio->moderation_status === 'approved';
        }

        return Audio::whereKey($video->audio_id)->availableTo($user)->exists();
    }

    public static function at(string $value, string $timezone): string
    {
        $valid = in_array($timezone, DateTimeZone::listIdentifiers(), true)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value);
        if ($valid) {
            try {
                $date = new DateTimeImmutable($value);
                $errors = DateTimeImmutable::getLastErrors();
                $local = $date->setTimezone(new DateTimeZone($timezone));
                $valid = (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
                    && $date->getOffset() === $local->getOffset()
                    && $date->format('Y-m-d\TH:i:s') === $local->format('Y-m-d\TH:i:s')
                    && $date->getTimestamp() > time();
            } catch (\Throwable) {
                $valid = false;
            }
        }
        if (!$valid) {
            throw ValidationException::withMessages(['publish_at' => ['Supply a future ISO-8601 second with an offset valid in the selected IANA timezone.']]);
        }

        return Billing::timestamp($date->getTimestamp());
    }

    public function edit(User $user, int $id, array $input): Video
    {
        return self::transaction($id, function (Video $video) use ($user, $input): Video {
            self::owned($user, $video->id);
            Artists::owned($user);
            $this->version($video, (int) $input['version']);
            abort_unless($video->publication_state === 'draft', 409, 'Cancel the schedule before editing a release.');
            unset($input['version']);
            if (array_key_exists('rights_confirmed', $input)) {
                abort_unless($input['rights_version'] === config('policies.rights_version'), 422, 'Accept the current rights declaration.');
                unset($input['rights_confirmed']);
                $input['rights_confirmed_at'] = now();
            }
            self::save($video, [...$input, 'version' => $video->version + 1]);
            if (array_key_exists('reuse_content', $input)) {
                Audio::where('source_video_id', $video->id)->update(['reuse_allowed' => $input['reuse_content']]);
            }

            return $video;
        });
    }

    public function transition(User $user, int $id, string $action, array $input): Video
    {
        $at = $action === 'schedule' ? self::at($input['publish_at'], $input['timezone']) : null;

        return self::transaction($id, function (Video $video) use ($user, $action, $input, $at): Video {
            self::owned($user, $video->id);
            Artists::owned($user);
            $this->version($video, (int) $input['version']);
            abort_if($video->publication_state === 'published', 409, 'Published videos cannot return to draft or be scheduled.');
            if ($action === 'cancel') {
                abort_unless($video->publication_intent === 'draft', 409, 'An immediate upload cannot be converted to a private draft. Upload it privately first.');
                self::save($video, [
                    'publication_state' => 'draft', 'publication_intent' => 'draft', 'publish_at' => null,
                    'publish_timezone' => null, 'release_error' => null, 'version' => $video->version + 1,
                    'status' => $video->processing_state === 'ready' ? 'draft' : $video->status,
                ]);
            } else {
                abort_unless(Artists::owned($user)->moderation_status === 'approved', 409, 'Artist is not eligible to publish.');
                abort_unless($video->processing_state === 'ready' && self::eligible($video), 409, 'Release is not ready or eligible for publication.');
                ReleaseMedia::assertPrivate($video);
                if ($action === 'schedule') {
                    app(ArtistCapabilities::class)->require($user->id, 'release_scheduling');
                    abort_unless($at > Billing::timestamp(), 422, 'Publication time must still be in the future.');
                    self::save($video, [
                        'publication_state' => 'scheduled', 'publication_intent' => 'draft',
                        'publish_at' => $at, 'publish_timezone' => $input['timezone'], 'status' => 'scheduled',
                        'release_error' => null, 'version' => $video->version + 1,
                    ]);
                } else {
                    $this->publish($video);
                }
            }

            return $video;
        });
    }

    public function runDue(): void
    {
        $lastId = 0;
        do {
            $videos = Video::where('publication_state', 'scheduled')->where('status', '!=', Video::STATUS_DELETED)
                ->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();
            foreach ($videos as $selected) {
                $lastId = $selected->id;
                try {
                    self::transaction($selected->id, function (Video $video): void {
                        if ($video->publication_state !== 'scheduled' || $video->status === Video::STATUS_DELETED) {
                            return;
                        }
                        $reason = null;
                        if (!app(ArtistCapabilities::class)->forUser($video->user_id)['release_scheduling']) {
                            $reason = 'scheduling_access_ended';
                        } elseif (!self::eligible($video) || !Artist::where('user_id', $video->user_id)->where('moderation_status', 'approved')->exists()) {
                            $reason = 'release_ineligible';
                        } elseif ($video->processing_state !== 'ready') {
                            $reason = 'media_not_ready';
                        }
                        if (!$reason && $video->publish_at && $video->publish_at->getTimestamp() <= time()) {
                            try {
                                ReleaseMedia::assertPrivate($video);
                            } catch (ValidationException) {
                                $reason = 'media_unavailable';
                            }
                        }
                        if ($reason) {
                            self::save($video, [
                                'publication_state' => 'draft', 'publication_intent' => 'draft',
                                'status' => $video->processing_state === 'ready' ? 'draft' : $video->status,
                                'release_error' => $reason, 'version' => $video->version + 1,
                            ]);
                            Notification::create(['user_id' => $video->user_id, 'type' => 'release_paused', 'data' => [
                                'release_id' => $video->id, 'reason' => $reason,
                                'message' => 'Your scheduled release is now a draft. Review it before scheduling or publishing again.',
                            ]]);
                        } elseif ($video->publish_at && $video->publish_at->getTimestamp() <= time()) {
                            $this->publish($video);
                        }
                    });
                } catch (\Throwable) {
                    // One unavailable object or account must not starve the remaining releases.
                    \Spark\Facades\Log::error('Scheduled release {id} could not be checked; it will be retried.', ['id' => $selected->id]);
                }
            }
        } while (count($videos) === 100);
    }

    private function publish(Video $video): void
    {
        self::save($video, [
            'publication_state' => 'published', 'status' => Video::STATUS_PUBLISHED,
            'published_at' => Billing::timestamp(), 'release_error' => null, 'version' => $video->version + 1,
        ]);
    }

    private function version(Video $video, int $version): void
    {
        abort_unless($video->version === $version, 409, 'Release version is stale. Reload before editing.');
    }

    public static function save(Video $video, array $attributes): void
    {
        if (!$video->update($attributes)) {
            throw new \RuntimeException('Release could not be saved.');
        }
    }
}
