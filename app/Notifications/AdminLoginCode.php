<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AdminLoginCode extends Notification implements ShouldBeEncrypted
{
    public function __construct(public readonly int $userId, public readonly string $code) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $challenge = Cache::get('admin-login-code:'.$this->userId);

        return $notifiable->exists
            && (int) $notifiable->id === $this->userId
            && $notifiable->role === 'super_admin'
            && $notifiable->account_status === 'active'
            && is_array($challenge)
            && ($challenge['expires_at'] ?? 0) >= now()->timestamp
            && Hash::check($this->code, $challenge['hash'] ?? '');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your ATS CV Detector admin sign-in code')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('Enter this six-digit code to finish signing in to the private admin dashboard:')
            ->line($this->code)
            ->line('This code expires in 10 minutes and can be used once.')
            ->line('If you did not request this code, secure your account and ignore this email.');
    }
}
