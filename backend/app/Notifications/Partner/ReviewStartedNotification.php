<?php

namespace App\Notifications\Partner;

use App\Models\RestaurantApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RestaurantApplication $application) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your application is under review')
            ->line('Our team has started reviewing '.$this->application->trading_name.'.');
    }
}
