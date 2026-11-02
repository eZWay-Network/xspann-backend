<?php

namespace App\Http\Resources\Staff;

use App\Services\Staff\{Context, Permissions, Sessions};
use Spark\Facades\DB;

final class SharedProps
{
    public static function navigation(Context $context): array
    {
        $filter = function (array $item) use ($context, &$filter): ?array {
            if (isset($item['ability']) && !$context->can($item['ability'])) {
                return null;
            }

            $result = [
                'key' => $item['route'] ?? $item['key'],
                'title' => $item['title'],
                'icon' => $item['icon'],
            ];

            if (isset($item['children'])) {
                $result['children'] = array_values(array_filter(array_map($filter, $item['children'])));

                return $result['children'] ? $result : null;
            }

            return $result + ['path' => (string) route($item['route'])];
        };
        $groups = [];

        foreach (config('staff-navigation') as $group) {
            $items = array_values(array_filter(array_map($filter, $group['items'])));

            if ($items) {
                $groups[] = [
                    'key' => $group['key'] ?? $items[0]['key'],
                    'label' => $group['label'],
                    'placement' => $group['placement'],
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    public static function auth(Context $context): array
    {
        return [
            'user' => [
                'id' => (int) $context->user->id,
                'name' => $context->user->name,
                'username' => $context->user->username,
                // Avoid storage-signing or private avatar references in shared props.
                'avatar_url' => null,
            ],
            'role' => $context->membership->role,
            'permissions' => Permissions::forRole($context->membership->role),
            'mfa_expires_at' => Sessions::iso($context->session->mfa_expires_at),
            'session_expires_at' => Sessions::iso($context->session->expires_at),
        ];
    }

    public static function preferences(Context $context): array
    {
        $row = DB::table('staff_preferences')->where('user_id', $context->user->id)->first();

        return ['timezone' => $row->timezone ?? 'UTC', 'locale' => $row->locale ?? 'en', 'attention_enabled' => (bool) ($row->attention_enabled ?? true)];
    }
}
