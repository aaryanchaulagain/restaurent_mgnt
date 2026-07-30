<?php

namespace App\Notifications\Partner;

use App\Models\RestaurantApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationApprovedNotification extends Notification implements ShouldQueue
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
            ->subject('Congratulations — your restaurant was approved')
            ->line($this->application->trading_name.' has been approved to join Suvakamana.')
            ->action('Open restaurant dashboard', rtrim(config('suvakamana.frontend_url'), '/').'/restaurant/dashboard');
    }
}
