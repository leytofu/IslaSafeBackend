<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class OtpCodeNotification extends Notification
{
    public function __construct(
        public readonly string $otpCode,
        public readonly int $expiresInMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'IslaSafe');

        return (new MailMessage)
            ->subject("Your {$appName} Verification Code")
            ->greeting('Hello!')
            ->line("You requested a password reset for your {$appName} account.")
            ->line('Your verification code is:')
            ->line(new HtmlString("<strong style=\"font-size: 1.5rem; letter-spacing: 0.25em; font-family: monospace;\">{$this->otpCode}</strong>"))
            ->line("This code will expire in {$this->expiresInMinutes} minutes.")
            ->line('If you did not request this, please ignore this email or contact support if you have concerns.')
            ->salutation("Stay safe,\nThe {$appName} Team");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'otp_code' => $this->otpCode,
            'expires_in_minutes' => $this->expiresInMinutes,
        ];
    }
}
