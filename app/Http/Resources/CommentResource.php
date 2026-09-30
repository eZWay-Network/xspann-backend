<?php

namespace App\Http\Resources;

use App\Models\CommentReaction;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        $reactions = [];
        foreach (CommentReaction::TYPES as $type) {
            $reactions[$type] = (int) $this->{"reactions_{$type}_count"};
        }

        return [
            'id' => $this->id,
            'video_id' => $this->video_id,
            'parent_id' => $this->parent_id,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', UserResource::make(...)),
            'replies_count' => (int) $this->replies_count,
            'reactions' => $reactions,
            'viewer_reaction' => $this->viewer_reaction,
        ];
    }
}
