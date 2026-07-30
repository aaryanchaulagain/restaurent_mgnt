<?php

namespace App\Notifications\Partner;

use App\Models\RestaurantApplication;
use App\Models\RestaurantCommissionAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommissionOfferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public RestaurantApplication $application,
        public RestaurantCommissionAgreement $agreement,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('suvakamana.frontend_url'), '/').'/partner/applications/'.$this->application->public_id;

        return (new MailMessage)
            ->subject('Commission offer ready for review')
            ->line('A commission agreement is ready for '.$this->application->trading_name.'.')
            ->line('Proposed rate: '.($this->agreement->commission_rate ?? '0').'%')
            ->action('Review offer', $url);
    }
}
