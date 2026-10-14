<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailCode extends Notification
{
    public function __construct(public readonly string $code) {}

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
