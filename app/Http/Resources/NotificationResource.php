<?php

namespace App\Http\Resources;

use App\Services\StorageService;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;
use function is_array;

class NotificationResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data(),
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }

    private function data(): array
    {
        $data = $this->data;

        if (isset($data['actor']) && is_array($data['actor'])) {
            $data['actor']['avatar'] = StorageService::publicUrl($data['actor']['avatar'] ?? null);
        }

        return $data;
    }
}
