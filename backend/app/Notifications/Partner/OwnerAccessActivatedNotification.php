<?php

namespace App\Notifications\Partner;

use App\Models\Restaurant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OwnerAccessActivatedNotification extends Notification implements ShouldQueue
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
            ->subject('Restaurant owner access activated')
            ->line('You now have restaurant owner access for '.$this->restaurant->trading_name.'.')
            ->action('Go to dashboard', rtrim(config('suvakamana.frontend_url'), '/').'/restaurant/dashboard');
    }
}
