<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;

class VerifyEmailCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $code)
    {
        $this->onConnection(config('performance.background_connection'))->onQueue('mail')->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 60];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $notifiable->refresh();

        return ! $notifiable->hasVerifiedEmail()
            && $notifiable->email_verification_code_expires_at?->isFuture()
            && $notifiable->email_verification_code_email === $notifiable->email
            && Hash::check($this->code, $notifiable->email_verification_code_hash ?? '');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your ATS CV email verification code')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('Enter this six-digit code in ATS CV to verify your email address:')
            ->line($this->code)
            ->line('This code expires in 10 minutes. Requesting another code replaces this one.')
            ->line('If you did not request this code, you can ignore this email.');
    }
}
