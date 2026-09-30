<?php

namespace App\Jobs;

use App\Models\User;
use Spark\Facades\Mail;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use RuntimeException;

class SendAccountEmail implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $userId, public string $subject, public string $body)
    {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (
            $user && !Mail::to($user->email, $user->name)
                ->subject($this->subject)
                ->view('mails.account', [
                    'body' => $this->body,
                    'subject' => $this->subject,
                    'user' => $user,
                ])
                ->send()
        ) {
            throw new RuntimeException('Account email could not be delivered.');
        }
    }
}
