<?php

namespace App\Services\Staff;

use App\Models\User;
use Spark\Facades\DB;
use Spark\Http\Request;

final class Context
{
    private ?array $preferences = null;

    public function __construct(
        public readonly User $user,
        public readonly object $membership,
        public readonly object $session,
    ) {
    }

    public function preferences(): array
    {
        if ($this->preferences === null) {
            $row = DB::table('staff_preferences')->where('user_id', $this->user->id)->first();
            $pageSize = (int) ($row->page_size ?? 20);
            $this->preferences = [
                'timezone' => $row->timezone ?? config('app.timezone', 'UTC'),
                'locale' => $row->locale ?? 'en',
                'page_size' => in_array($pageSize, [10, 20, 50], true) ? $pageSize : 20,
                'attention_enabled' => (bool) ($row->attention_enabled ?? true),
                'version' => (int) ($row->version ?? 0),
            ];
        }

        return $this->preferences;
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
