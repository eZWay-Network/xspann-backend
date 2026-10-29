<?php

namespace App\Http\Resources;

use Spark\Carbon;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

/** Normalize explicitly selected moderation queue/history columns. Evidence uses a separate response. */
class ModerationResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        $data = array_intersect_key(parent::toArray($request), array_flip([
            'id',
            'target_type',
            'target_id',
            'reason',
            'action',
            'active',
            'status',
            'version',
            'decision_id',
            'report_id',
            'explanation',
            'resolution',
            'user_id',
            'reviewer_id',
            'staff_id',
            'created_at',
            'updated_at',
        ]));
        foreach (['created_at', 'updated_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Carbon::parse($data[$field]);
            }
        }
        foreach (['id', 'target_id', 'version', 'decision_id', 'report_id', 'user_id', 'reviewer_id', 'staff_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        if (array_key_exists('active', $data)) {
            $data['active'] = (bool) $data['active'];
        }

        return $data;
    }
}
