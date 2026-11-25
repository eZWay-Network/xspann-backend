<?php

namespace App\Services\Staff;

final class Permissions
{
    public static function forRole(string $role): array
    {
        return array_values(array_intersect(config("staff.roles.$role", []), config('staff.abilities', [])));
    }

    public static function allows(string $role, string $ability): bool
    {
        return in_array($ability, self::forRole($role), true);
    }

    public static function roles(): array
    {
        return ['moderator', 'senior_moderator', 'super_admin'];
    }

    public static function knownRoles(): array
    {
        return array_keys(config('staff.roles'));
    }
}
