<?php

namespace App\Notifications\Partner;

use App\Models\RestaurantApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationRejectedNotification extends Notification implements ShouldQueue
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
            ->subject('Update on your partner application')
            ->line('We were unable to approve '.$this->application->trading_name.' at this time.')
            ->line($this->application->rejection_reason ?: 'Please contact support for details.');
    }
}
