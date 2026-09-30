<?php

namespace App\Services;

use App\Models\Video;
use Spark\Facades\Auth;
use Spark\Facades\DB;
use function is_int;

class VideoActions
{
    public static function visible(Video|int $video): Video
    {
        if (is_int($video)) {
            $video = Video::findOrFail($video);
        }

        if (
            $video->status !== Video::STATUS_PUBLISHED ||
            Video::query()->whereKey($video->id)->visibleTo(Auth::user())->doesntExist()
        ) {
            abort(404, 'Video not found.');
        }

        return $video;
    }

    /** A row write serializes counters across workers on both SQLite and MySQL. */
    public static function transaction(int $id, callable $callback): mixed
    {
        return DB::transaction(function () use ($id, $callback) {
            Video::increment('views_count', 0, where: ['id' => $id]);

            return $callback(Video::findOrFail($id));
        });
    }
}
