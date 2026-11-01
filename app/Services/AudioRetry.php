<?php

namespace App\Services;

use App\Jobs\ProcessAudio;
use App\Models\{Audio, User};

class AudioRetry
{
    public static function retry(?int $audioId = null): bool
    {
        $ids = Audio::whereIn('status', ['failed', 'processing'])
            ->where('origin', 'upload')
            ->where('moderation_status', 'approved')
            ->whereNotNull('rights_confirmed_at')
            ->whereIn('user_id', User::select('users.id')->visibleTo(null))
            ->when($audioId !== null, fn($query) => $query->whereKey($audioId))
            ->pluck('id');

        $queued = false;
        foreach ($ids as $id) {
            $updated = Audio::whereKey($id)
                ->whereIn('status', ['failed', 'processing'])
                ->where('moderation_status', 'approved')
                ->whereIn('user_id', User::select('users.id')->visibleTo(null))
                ->update(['status' => 'processing', 'updated_at' => now()]);

            if ($updated === 0 && !Audio::whereKey($id)->where('status', 'processing')->where('moderation_status', 'approved')->exists()) {
                continue;
            }

            try {
                ProcessAudio::dispatch((int) $id)->send();
            } catch (\Throwable $exception) {
                Audio::whereKey($id)->where('status', 'processing')->update(['status' => 'failed', 'updated_at' => now()]);
                throw $exception;
            }
            $queued = true;
        }

        return $queued;
    }
}
