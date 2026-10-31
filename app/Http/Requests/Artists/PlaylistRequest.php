<?php

namespace App\Http\Requests\Artists;

use Spark\Http\Request;

class PlaylistRequest extends ArtistWriteRequest
{
    public function rules(): array
    {
        $updating = $this->getMethod() === Request::METHOD_PATCH;

        return [
            'title' => [...($updating ? ['sometimes'] : []), 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'genre_id' => ['sometimes', 'nullable', 'integer', 'min:1', ['exists' => ['artist_genres', 'id']]],
            'category' => ['sometimes', 'required', 'string', ['in' => config('artists.playlist_categories')]],
            'visibility' => ['sometimes', 'required', 'string', 'in:public,private'],
            ...($updating ? ['version' => ['required', 'integer', 'min:1']] : []),
        ];
    }
}
