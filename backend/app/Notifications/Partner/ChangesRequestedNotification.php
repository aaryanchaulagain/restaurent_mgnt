<?php

namespace App\Notifications\Partner;

use App\Models\RestaurantApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangesRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RestaurantApplication $application) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('suvakamana.frontend_url'), '/').'/partner/applications/'.$this->application->public_id;

        return (new MailMessage)
            ->subject('Updates requested for your application')
            ->line($this->application->changes_requested_reason ?: 'Please update your application.')
            ->action('Update application', $url);
    }
}
