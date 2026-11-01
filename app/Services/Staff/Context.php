<?php

namespace App\Services\Staff;

use App\Models\User;
use Spark\Http\Request;

final class Context
{
    public function __construct(
        public readonly User $user,
        public readonly object $membership,
        public readonly object $session,
    ) {
    }

    public static function from(Request $request): self
    {
        return $request->attributes['staff_context'] ?? throw new StaffException('Please sign in again.', 401);
    }

    public function can(string $ability): bool
    {
        return Permissions::allows($this->membership->role, $ability);
    }

    public function authorize(string $ability): void
    {
        if (!$this->can($ability)) {
            throw new StaffException('Your role does not have access to this action.', 403);
        }
    }

    public function recent(int $seconds): bool
    {
        return $this->session->mfa_at !== null && strtotime($this->session->mfa_at . ' UTC') > time() - $seconds;
    }
}
