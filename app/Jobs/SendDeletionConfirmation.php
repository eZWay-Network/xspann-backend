<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AccountSecurity;
use Spark\Facades\{Cache, Hash};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class SendDeletionConfirmation implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public string $encryptedEmail)
    {
    }

    public function handle(): void
    {
        $email = Hash::decrypt($this->encryptedEmail);

        Cache::lock('accounts.deletion-email.' . hash('sha256', $email), 30)->block(5, function () use ($email): void {
            $user = User::where('email', $email)->whereIn('status', ['active', 'suspended'])->first();
            if (
                !$user || query('account_action_tokens')->where('user_id', $user->id)->where('purpose', 'email_deletion')
                    ->where('created_at', '>', now()->subMinutes(5)->toDateTimeString())->exists()
            ) {
                return;
            }
            $token = AccountSecurity::issue($user->id, 'email_deletion', 3600);
            $url = htmlspecialchars(rtrim(config('app.frontend_url'), '/') . '/delete-account?token=' . urlencode($token), ENT_QUOTES, 'UTF-8');
            try {
                (new SendAccountEmail(
                    $user->id,
                    'Confirm your XPN Social account deletion',
                    "Only if you requested deletion, open <a href=\"$url\">this confirmation link</a> within one hour. You will confirm deletion on that page. Otherwise ignore this email."
                ))->handle();
            } catch (\Throwable $exception) {
                query('account_action_tokens')->where('token_hash', hash('sha256', $token))->delete();
                throw new \RuntimeException('Deletion confirmation delivery failed.');
            }
        });
    }
}
