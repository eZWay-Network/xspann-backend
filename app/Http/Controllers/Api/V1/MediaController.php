<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Audio, Video};
use App\Services\{ReleaseMedia, StorageService};
use Spark\Http\{Request, Response};

class MediaController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $expires = $request->query('expires');
        $signature = $request->query('signature');
        abort_unless(is_scalar($expires) && ctype_digit((string) $expires) && (int) $expires >= time()
            && (int) $expires <= time() + 300 && is_string($signature)
            && hash_equals(hash_hmac('sha256', "media:$token:$expires", config('app.key')), $signature), 404, 'Media not found.');
        $reference = base64_decode(strtr($token, '-_', '+/'), true);
        $location = $reference ? StorageService::location($reference) : null;
        abort_unless((bool) $location, 404, 'Media not found.');
        $values = StorageService::referenceValues($reference);
        $visible = Video::published()->visibleTo($request->user())->where(function ($query) use ($values): void {
            $query->whereIn('storage_path', $values)->orWhereIn('thumbnail_url', $values);
        })->exists();
        $visible = $visible || Audio::availableTo($request->user())->whereIn('storage_path', $values)->exists();
        abort_unless($visible, 404, 'Media not found.');

        return ReleaseMedia::deliver($reference, $request);
    }
}
