<?php

namespace App\Http\Requests\Artists;

class PutArtistRequest extends ArtistWriteRequest
{
    public function rules(): array
    {
        return [
            'stage_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'genre_ids' => ['sometimes', 'array', 'max:' . config('artists.genres_per_artist')],
            'genre_ids.*' => ['required', 'integer', 'min:1', 'distinct', ['exists' => ['artist_genres', 'id']]],
            'featured_video_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'listed' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
