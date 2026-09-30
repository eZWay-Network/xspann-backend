<?php

namespace App\Http\Resources;

use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
