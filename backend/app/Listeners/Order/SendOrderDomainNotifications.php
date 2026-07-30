<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderDomainEvent;
use App\Events\Order\OrderPlaced;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\Order\OrderPlacedCustomerNotification;
use App\Notifications\Order\OrderStatusUpdatedNotification;
use Illuminate\Support\Facades\Notification;

class SendOrderDomainNotifications
{
    public function handlePlaced(OrderPlaced $event): void
    {
        $order = Order::query()->where('public_id', $event->orderPublicId)->with('items')->first();
        if (! $order) {
            return;
        }

        if ($order->customer_id) {
            User::query()->find($order->customer_id)?->notify(new OrderPlacedCustomerNotification($order));
        } elseif ($order->customer_email_snapshot) {
            Notification::route('mail', $order->customer_email_snapshot)
                ->notify(new OrderPlacedCustomerNotification($order));
        }

        $restaurant = Restaurant::query()->find($order->restaurant_id);
        if ($restaurant?->business_email) {
            Notification::route('mail', $restaurant->business_email)
                ->notify(new OrderStatusUpdatedNotification($order, 'New order received'));
        }
    }

    public function handleStatus(OrderDomainEvent $event): void
    {
        if ($event instanceof OrderPlaced) {
            return;
        }

        $order = Order::query()->where('public_id', $event->orderPublicId)->first();
        if (! $order) {
            return;
        }

        $headline = match ($event->newStatus) {
            'accepted' => 'Order accepted',
            'rejected' => 'Order rejected',
            'preparing' => 'Preparation started',
            'ready_for_pickup' => 'Order ready for pickup',
            'completed_pickup' => 'Pickup completed',
            'cancelled' => 'Order cancelled',
            'expired' => 'Order expired',
            default => 'Order updated',
        };

        if ($order->customer_id) {
            User::query()->find($order->customer_id)?->notify(new OrderStatusUpdatedNotification($order, $headline));
        } elseif ($order->customer_email_snapshot) {
            Notification::route('mail', $order->customer_email_snapshot)
                ->notify(new OrderStatusUpdatedNotification($order, $headline));
        }
    }
}
