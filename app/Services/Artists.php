<?php

namespace App\Services;

use App\Models\{Artist, ArtistLink, User, Video};
use DateTimeImmutable;
use DateTimeZone;
use Spark\Facades\DB;
use Spark\Foundation\Exceptions\ValidationException;
use function count;

class Artists
{
    public function __construct(private ArtistCapabilities $capabilities)
    {
    }

    public static function owned(User $user): Artist
    {
        return Artist::where('user_id', $user->id)->firstOrFail();
    }

    public function save(User $owner, array $input): Artist
    {
        $oldImage = null;

        try {
            $artist = PendingUploads::attach($owner->id, function () use ($owner, $input, &$oldImage): Artist {
                $owner = User::findOrFail($owner->id);
                $this->eligible($owner);
                $artist = Artist::where('user_id', $owner->id)->first();
                $oldImage = $artist?->image;

                $slugTaken = Artist::where('slug', $input['slug'])
                    ->when($artist, fn($query) => $query->where('id', '!=', $artist->id))->exists();

                if ($slugTaken) {
                    throw ValidationException::withMessages(['slug' => ['The artist slug is already in use.']]);
                }

                if (isset($input['image'])) {
                    StorageService::validateOwner($input['image'], $owner->id, 'image', 'artist-images', true);
                }

                if (isset($input['featured_video_id'])) {
                    // Preserving saved paid data is allowed after downgrade; changing it is not.
                    if (!$artist || (int) $artist->featured_video_id !== (int) $input['featured_video_id']) {
                        $this->capabilities->require($owner->id, 'featured_release');
                        abort_unless(
                            Video::where('user_id', $owner->id)->where('visibility', 'public')
                                ->published()->visibleTo($owner)->whereKey($input['featured_video_id'])->lockForUpdate()->first() !== null,
                            422,
                            'Select your own published public video as the featured release.',
                        );
                    }
                }

                $attributes = array_diff_key($input, ['genre_ids' => true]);
                $attributes['stage_name'] = trim($attributes['stage_name']);

                if ($artist) {
                    if (!$artist->fill($attributes)->save()) {
                        throw new \RuntimeException('Artist profile could not be saved.');
                    }
                } else {
                    $artist = Artist::create([
                        ...$attributes,
                        'user_id' => $owner->id,
                        'data_since' => now(),
                    ]);
                }

                if (array_key_exists('genre_ids', $input)) {
                    $artist->genres()->sync(array_map('intval', $input['genre_ids']));
                }

                return $artist;
            });
        } catch (\PDOException $exception) {
            // The unique index arbitrates simultaneous enrollment using the same slug.
            if (Artist::where('slug', $input['slug'])->where('user_id', '!=', $owner->id)->exists()) {
                throw ValidationException::withMessages(['slug' => ['The artist slug is already in use.']]);
            }

            throw $exception;
        }

        if ($oldImage && $oldImage !== $artist->image) {
            MediaProcessor::cleanup($owner->id, [$oldImage]);
        }

        ArtistStatistics::collect($artist->id);

        return $artist;
    }

    public function replaceLinks(User $owner, array $links): Artist
    {
        return DB::transaction(function () use ($owner, $links): Artist {
            $artist = $this->lock($owner);

            if (count($links) > $this->capabilities->forUser($owner->id)['links']) {
                $this->capabilities->deny('links');
            }

            $artist->links()->delete();

            foreach ($links as $position => $link) {
                ArtistLink::create([
                    'artist_id' => $artist->id,
                    'label' => trim($link['label']),
                    'type' => $link['type'],
                    'url' => $link['url'],
                    'position' => $position,
                ]);
            }

            return $artist;
        });
    }

    /** Caller owns the transaction. A write first also serializes SQLite writers. */
    public function lock(User $owner): Artist
    {
        User::increment('id', 0, where: ['id' => $owner->id]);
        $current = User::visibleTo(null)->findOrFail($owner->id);
        $this->eligible($current);

        return Artist::where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
    }

    private function eligible(User $owner): void
    {
        $birth = $owner->date_of_birth;
        $date = is_string($birth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $birth, new DateTimeZone('UTC')) : false;
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        abort_unless(
            $date && $date->format('Y-m-d') === $birth && $date <= $today
            && $date->diff($today)->y >= config('policies.minimum_age', 18),
            403,
            'Adult enrollment is required for an artist profile.',
        );
    }
}
