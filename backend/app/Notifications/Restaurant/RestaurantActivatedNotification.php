<?php

namespace App\Notifications\Restaurant;

use App\Models\Restaurant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RestaurantActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Restaurant $restaurant) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your restaurant is now live on Suvakamana')
            ->line($this->restaurant->trading_name.' is published on the marketplace.')
            ->action('View dashboard', rtrim(config('suvakamana.frontend_url'), '/').'/restaurant/dashboard');
    }
}
