<?php

namespace App\Http\Requests\Artists;

class PutPlaylistItemsRequest extends ArtistWriteRequest
{
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'video_ids' => ['present', 'array', 'max:' . config('artists.videos_per_playlist')],
            'video_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
