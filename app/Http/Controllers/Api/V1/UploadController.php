<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\{AvatarUploadRequest, LocalAudioUploadRequest, LocalVideoUploadRequest, SignedVideoUploadRequest};
use App\Services\{StorageService, ChunkUploads, PendingUploads};
use Spark\Http\{Request, Response};

class UploadController extends Controller
{
    public function avatar(AvatarUploadRequest $request): Response
    {
        return $this->upload($request, 'avatars', 'avatar_url');
    }

    public function local(LocalVideoUploadRequest $request): Response
    {
        return $this->upload($request, 'videos', 'video_url');
    }

    public function audio(LocalAudioUploadRequest $request): Response
    {
        return $this->upload($request, 'sounds', 'audio_url');
    }

    public function video(SignedVideoUploadRequest $request): Response
    {
        if (StorageService::supportsSignedUploads()) {
            try {
                $path = StorageService::pathFor($request->user('id'), $request->validated('filename'));
                $contentType = $request->validated('content_type');
                $acl = config('storage.disks.' . StorageService::diskName() . '.acl');
                $disk = StorageService::disk();
                $uploadUrl = $disk->temporaryUploadUrl($path, 900, $contentType, $acl);

                PendingUploads::track($request->user('id'), $path);

                $headers = ['Content-Type' => $contentType];

                if ($acl !== null) {
                    $headers['x-amz-acl'] = $acl;
                }

                return json([
                    'data' => [
                        'upload_method' => 'signed_url',
                        'storage_path' => $path,
                        'video_url' => StorageService::publicUrl($disk->url($path)),
                        'upload_url' => $uploadUrl,
                        'headers' => $headers,
                    ],
                ]);
            } catch (\InvalidArgumentException) {
                // Use multipart when direct upload signing is not configured.
            }
        }

        return json([
            'data' => [
                'upload_method' => 'multipart',
                'upload_url' => url('api/v1/uploads/videos/local'),
                'storage_path' => null,
                'headers' => [],
                'field_name' => 'file',
                'message' => 'Upload the video through the backend multipart endpoint.',
            ],
        ]);
    }

    public function videoChunk(Request $request, ChunkUploads $chunks): Response
    {
        $data = $request->validate(ChunkUploads::rules(true));

        $chunks->store($request->user('id'), $data->toArray(), $request->file('chunk'));

        return json([
            'data' => [
                'upload_method' => 'chunked',
                'upload_id' => $data['upload_id'],
                'chunk_index' => (int) $data['chunk_index'],
                'total_chunks' => (int) $data['total_chunks'],
            ],
        ], 201);
    }

    public function completeVideoChunks(Request $request, ChunkUploads $chunks): Response
    {
        $data = $request->validate(ChunkUploads::rules());

        $path = $chunks->complete($request->user('id'), $data->toArray());

        return json([
            'data' => [
                'upload_method' => 'chunked',
                'storage_path' => $path,
                'video_url' => StorageService::publicUrl(StorageService::storedValue($path)),
            ],
        ], 201);
    }

    private function upload(Request $request, string $kind, string $urlKey): Response
    {
        $path = StorageService::storeFile($request->user('id'), $request->file('file'), $kind);

        return json([
            'data' => [
                'upload_method' => 'multipart',
                'storage_path' => $path,
                $urlKey => StorageService::publicUrl(StorageService::storedValue($path)),
            ],
        ], 201);
    }
}
