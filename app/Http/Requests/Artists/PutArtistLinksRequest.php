<?php

namespace App\Http\Requests\Artists;

use Spark\Foundation\Exceptions\ValidationException;

class PutArtistLinksRequest extends ArtistWriteRequest
{
    public function rules(): array
    {
        return [
            'links' => ['present', 'array', 'max:5'],
            'links.*.label' => ['required', 'string', 'max:120'],
            'links.*.type' => ['required', 'string', ['in' => config('artists.link_types')]],
            'links.*.url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }

    public function passedValidation(): void
    {
        parent::passedValidation();

        foreach ($this->input('links') as $index => $link) {
            $parts = parse_url($link['url']);

            if (array_diff(array_keys($link), ['label', 'type', 'url']) || trim($link['label']) === ''
                || !$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $link['url'])) {
                throw ValidationException::withMessages(["links.$index" => ['Use label, type and an HTTPS URL without credentials or whitespace.']]);
            }
        }
    }
}
