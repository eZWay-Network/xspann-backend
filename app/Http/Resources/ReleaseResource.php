<?php

namespace App\Http\Resources;

use App\Services\ReleaseMedia;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class ReleaseResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id, 'version' => $this->version,
            'caption' => $this->caption, 'visibility' => $this->visibility,
            'reuse_content' => $this->reuse_content, 'rights_version' => $this->rights_version,
            'rights_confirmed_at' => $this->rights_confirmed_at,
            'processing_state' => $this->processing_state, 'publication_state' => $this->publication_state,
            'publication_intent' => $this->publication_intent, 'status' => $this->status,
            'publish_at' => $this->publish_at, 'timezone' => $this->publish_timezone,
            'published_at' => $this->published_at, 'error_code' => $this->release_error,
            'moderation_status' => $this->moderation_status,
            'preview' => $this->moderation_status === 'approved' ? [
                'video' => ReleaseMedia::previewUrl($this->id, 'video'),
                'thumbnail' => $this->thumbnail_url ? ReleaseMedia::previewUrl($this->id, 'thumbnail') : null,
                'original_audio' => $this->original_audio_id ? ReleaseMedia::previewUrl($this->id, 'original_audio') : null,
            ] : null,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
