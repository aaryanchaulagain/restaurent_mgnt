<?php

namespace App\Notifications\Order;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPlacedCustomerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('suvakamana.frontend_url'), '/').'/orders/'.$this->order->order_number;

        return (new MailMessage)
            ->subject('Order received — '.$this->order->order_number)
            ->line('Your order has been sent to the restaurant.')
            ->line('Payment status: '.$this->order->payment_status)
            ->action('View order', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_public_id' => $this->order->public_id,
            'order_number' => $this->order->order_number,
            'restaurant_id' => $this->order->restaurant_id,
            'total_cents' => $this->order->total_cents,
            'fulfilment_type' => $this->order->fulfilment_type,
            'item_count' => $this->order->items()->count(),
            'placed_at' => $this->order->placed_at?->toIso8601String(),
        ];
    }
}
