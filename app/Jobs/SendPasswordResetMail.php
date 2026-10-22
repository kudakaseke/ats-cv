<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendPasswordResetMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $userId, public string $token)
    {
        $this->onConnection(config('performance.background_connection'))->onQueue('mail')->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if ($user && Password::broker()->tokenExists($user, $this->token)) {
            $user->notify(new ResetPassword($this->token));
        }
    }
}
