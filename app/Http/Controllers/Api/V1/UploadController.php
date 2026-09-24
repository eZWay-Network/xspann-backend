<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\AvatarUploadRequest;
use App\Http\Requests\Uploads\LocalAudioUploadRequest;
use App\Http\Requests\Uploads\LocalVideoUploadRequest;
use App\Http\Requests\Uploads\SignedVideoUploadRequest;
use App\Services\VideoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class UploadController extends Controller
{
    private const LOCAL_VIDEO_MAX_BYTES = 512000 * 1024;

    private const VIDEO_CHUNK_MAX_KILOBYTES = 2048;

    public function avatar(AvatarUploadRequest $request, VideoStorage $storage): JsonResponse
    {
        $path = $storage->storeAvatar($request->user()->id, $request->file('file'));

        return response()->json([
            'data' => [
                'upload_method' => 'multipart',
                'storage_path' => $path,
                'avatar_url' => $storage->publicUrl($path),
            ],
        ], 201);
    }

    public function video(SignedVideoUploadRequest $request, VideoStorage $storage): JsonResponse
    {
        $path = $storage->pathFor($request->user()->id, $request->validated('filename'));

        if (! $storage->supportsSignedUploads()) {
            return $this->fallbackUploadResponse();
        }

        try {
            $disk = $storage->disk();

            if (! method_exists($disk, 'temporaryUploadUrl')) {
                return $this->fallbackUploadResponse();
            }

            $signed = $disk->temporaryUploadUrl($path, now()->addMinutes(15), [
                'ContentType' => $request->validated('content_type'),
            ]);
        } catch (Throwable) {
            return $this->fallbackUploadResponse();
        }

        return response()->json([
            'data' => [
                'upload_method' => 'signed_url',
                'storage_path' => $path,
                'upload_url' => $signed['url'],
                'headers' => $signed['headers'] ?? [],
            ],
        ]);
    }

    public function local(LocalVideoUploadRequest $request, VideoStorage $storage): JsonResponse
    {
        $path = $storage->storeLocalVideo($request->user()->id, $request->file('file'));

        return response()->json([
            'data' => [
                'upload_method' => 'multipart',
                'storage_path' => $path,
                'video_url' => $storage->publicUrl($path),
            ],
        ], 201);
    }

    public function videoChunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:10000'],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', Rule::in(['video/mp4', 'video/quicktime', 'video/webm'])],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.self::LOCAL_VIDEO_MAX_BYTES],
            'chunk' => ['required', 'file', 'max:'.self::VIDEO_CHUNK_MAX_KILOBYTES],
        ]);

        abort_if($data['chunk_index'] >= $data['total_chunks'], 422, 'Chunk index is outside the upload range.');

        $directory = $this->chunkDirectory($request->user()->id, $data['upload_id']);
        $absoluteDirectory = storage_path('app/private/'.$directory);

        File::ensureDirectoryExists($absoluteDirectory);
        File::put($absoluteDirectory.'/manifest.json', json_encode([
            'filename' => $data['filename'],
            'content_type' => $data['content_type'],
            'total_size' => $data['total_size'],
            'total_chunks' => $data['total_chunks'],
            'updated_at' => now()->toISOString(),
        ], JSON_PRETTY_PRINT));

        $request->file('chunk')->move($absoluteDirectory, $data['chunk_index'].'.part');

        return response()->json([
            'data' => [
                'upload_method' => 'chunked',
                'upload_id' => $data['upload_id'],
                'chunk_index' => $data['chunk_index'],
                'total_chunks' => $data['total_chunks'],
            ],
        ], 201);
    }

    public function completeVideoChunks(Request $request, VideoStorage $storage): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:10000'],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', Rule::in(['video/mp4', 'video/quicktime', 'video/webm'])],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.self::LOCAL_VIDEO_MAX_BYTES],
        ]);

        $directory = $this->chunkDirectory($request->user()->id, $data['upload_id']);
        $absoluteDirectory = storage_path('app/private/'.$directory);

        abort_unless(is_dir($absoluteDirectory), 422, 'Upload session was not found.');

        $assembledPath = $absoluteDirectory.'/assembled-video.tmp';
        $assembled = fopen($assembledPath, 'wb');

        try {
            for ($index = 0; $index < $data['total_chunks']; $index++) {
                $chunkPath = $absoluteDirectory.'/'.$index.'.part';
                abort_unless(is_file($chunkPath), 422, "Missing video chunk {$index}.");

                $chunk = fopen($chunkPath, 'rb');
                try {
                    stream_copy_to_stream($chunk, $assembled);
                } finally {
                    if (is_resource($chunk)) {
                        fclose($chunk);
                    }
                }
            }
        } finally {
            if (is_resource($assembled)) {
                fclose($assembled);
            }
        }

        abort_unless(filesize($assembledPath) === $data['total_size'], 422, 'Uploaded video size does not match the selected file.');

        $path = $storage->storeLocalVideoFromPath($request->user()->id, $assembledPath, $data['filename']);

        Storage::disk('local')->deleteDirectory($directory);

        return response()->json([
            'data' => [
                'upload_method' => 'chunked',
                'storage_path' => $path,
                'video_url' => $storage->publicUrl($path),
            ],
        ], 201);
    }

    public function audio(LocalAudioUploadRequest $request, VideoStorage $storage): JsonResponse
    {
        $path = $storage->storeLocalAudio($request->user()->id, $request->file('file'));

        return response()->json([
            'data' => [
                'upload_method' => 'multipart',
                'storage_path' => $path,
                'audio_url' => $storage->publicUrl($path),
            ],
        ], 201);
    }

    private function fallbackUploadResponse(): JsonResponse
    {
        return response()->json([
            'data' => [
                'upload_method' => 'multipart',
                'upload_url' => url('/api/v1/uploads/videos/local'),
                'storage_path' => null,
                'headers' => [],
                'field_name' => 'file',
                'message' => 'Cloud storage is not configured. Upload the video through the local multipart fallback endpoint.',
            ],
        ]);
    }

    private function chunkDirectory(int $userId, string $uploadId): string
    {
        return 'upload-chunks/videos/'.$userId.'/'.$uploadId;
    }
}
