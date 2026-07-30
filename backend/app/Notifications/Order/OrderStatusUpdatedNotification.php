<?php

namespace App\Notifications\Order;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public string $headline) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('suvakamana.frontend_url'), '/').'/orders/'.$this->order->order_number;

        return (new MailMessage)
            ->subject($this->headline.' — '.$this->order->order_number)
            ->line($this->headline)
            ->action('View order', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_public_id' => $this->order->public_id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status,
            'headline' => $this->headline,
        ];
    }
}
