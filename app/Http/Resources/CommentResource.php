<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('user');

        return [
            'id' => $this->id,
            'video_id' => $this->video_id,
            'parent_id' => $this->parent_id,
            'body' => $this->body,
            'user' => new UserResource($this->user),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
