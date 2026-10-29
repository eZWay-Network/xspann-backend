<?php

namespace App\Services;

use Spark\Facades\DB;

class AuditTrail
{
    public static function record(string $action, array $context = [], ?int $actorId = null, string $actorType = 'user'): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'action' => $action,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}
