<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends VerifyEmailNotification
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verify your Khana email')
            ->line('Welcome to Khana.')
            ->line('Please click the button below to verify your email address and activate your Khana account.')
            ->action('Verify Email Address', $verificationUrl)
            ->line('If you did not create a Khana account, no further action is required.');
    }

    protected function verificationUrl($notifiable): string
    {
        return self::frontendVerificationUrl($notifiable);
    }

    public static function frontendVerificationUrl($notifiable): string
    {
        $apiUrl = URL::temporarySignedRoute(
            'auth.email.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        $frontend = rtrim(config('suvakamana.frontend_url'), '/');

        return $frontend.'/verify-email?verify_url='.urlencode($apiUrl);
    }
}
