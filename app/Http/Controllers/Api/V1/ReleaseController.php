<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Releases\EditReleaseRequest;
use App\Http\Resources\ReleaseResource;
use App\Models\{Audio, Video};
use App\Services\{Artists, ReleaseMedia, Releases, VideoRetry};
use Spark\Facades\Cache;
use Spark\Foundation\Exceptions\ValidationException;
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class ReleaseController extends Controller
{
    public function index(Request $request): JsonResource
    {
        Artists::owned($request->user());
        $input = $request->validate([
            'processing_state' => ['sometimes', 'string', ['in' => ['processing', 'ready', 'failed']]],
            'publication_state' => ['sometimes', 'string', ['in' => ['draft', 'scheduled', 'published']]],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = Video::where('user_id', $request->user('id'))->where('status', '!=', Video::STATUS_DELETED);
        foreach (['processing_state', 'publication_state'] as $field) {
            if ($input->has($field)) {
                $query->where($field, $input[$field]);
            }
        }

        return ReleaseResource::collection($this->withOriginal($query)->orderByRaw('created_at DESC, id DESC')->paginate((int) $input->get('limit', 20)));
    }

    public function show(Request $request, string $video): JsonResource
    {
        $video = self::videoId($video);
        Artists::owned($request->user());
        Releases::owned($request->user(), $video);

        return $this->resource($video);
    }

    public function update(EditReleaseRequest $request, string $video, Releases $releases): JsonResource
    {
        $video = self::videoId($video);
        $releases->edit($request->user(), $video, $request->validated()->toArray());

        return $this->resource($video);
    }

    public function schedule(Request $request, string $video, Releases $releases): JsonResource
    {
        $video = self::videoId($video);
        $input = self::input($request, [
            'version' => ['required', 'integer', 'min:1'],
            'publish_at' => ['required', 'string', 'max:40'],
            'timezone' => ['required', 'string', 'max:80'],
        ]);
        $releases->transition($request->user(), $video, 'schedule', $input);

        return $this->resource($video);
    }

    public function cancel(Request $request, string $video, Releases $releases): JsonResource
    {
        $video = self::videoId($video);
        $releases->transition($request->user(), $video, 'cancel', self::input($request, ['version' => ['required', 'integer', 'min:1']]));

        return $this->resource($video);
    }

    public function publish(Request $request, string $video, Releases $releases): JsonResource
    {
        $video = self::videoId($video);
        $releases->transition($request->user(), $video, 'publish', self::input($request, ['version' => ['required', 'integer', 'min:1']]));

        return $this->resource($video);
    }

    public function retry(Request $request, string $video): JsonResource
    {
        $video = self::videoId($video);
        self::input($request, []);
        Cache::lock("videos.retry.$video", 30)->block(5, function () use ($request, $video): void {
            $current = Releases::owned($request->user(), $video);
            abort_unless(in_array($current->status, [Video::STATUS_PROCESSING, Video::STATUS_FAILED], true), 409, 'Only failed or interrupted encoding can be retried.');
            abort_unless(Releases::eligible($current), 409, 'Video is not eligible for encoding.');
            // Existing queued processing is idempotent; avoid adding a job on every owner retry.
            if ($current->status !== Video::STATUS_PROCESSING || $current->updated_at->getTimestamp() <= time() - 3600) {
                VideoRetry::retry($current->id);
            }
        });

        return $this->resource($video);
    }

    public function preview(Request $request, string $video, string $asset): Response
    {
        $video = self::videoId($video);
        $current = Releases::owned($request->user(), $video);
        abort_unless($current->moderation_status === 'approved', 404, 'Media not found.');
        $reference = match ($asset) {
            'video' => $current->storage_path,
            'thumbnail' => $current->thumbnail_url,
            'original_audio' => Audio::where('source_video_id', $video)->where('status', 'ready')
                ->where('moderation_status', 'approved')->first()?->storage_path,
            default => null,
        };
        abort_unless((bool) $reference, 404, 'Media not found.');

        return ReleaseMedia::deliver($reference, $request);
    }

    private static function videoId(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(ctype_digit($value) && $id !== false, 404, 'Video not found.');

        return $id;
    }

    public static function input(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['This field is not writable.']));
        }

        return $request->validate($rules)->toArray();
    }

    private function resource(int $video): ReleaseResource
    {
        return ReleaseResource::make($this->withOriginal(Video::query())->findOrFail($video));
    }

    private function withOriginal(\Spark\Database\QueryBuilder $query): \Spark\Database\QueryBuilder
    {
        return $query->select('videos.*')->selectSub(Audio::select('id')->whereColumn('source_video_id', 'videos.id')->limit(1), 'original_audio_id');
    }
}
