<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\OrderTransitionService;
use Illuminate\Console\Command;

class ExpireUnacceptedOrders extends Command
{
    protected $signature = 'orders:expire-unaccepted';

    protected $description = 'Expire orders that have not been accepted within the configured timeout.';

    public function handle(OrderTransitionService $transitions): int
    {
        $orders = Order::query()
            ->where('status', 'awaiting_restaurant')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            try {
                $transitions->transition($order, 'expired', null, 'system', 'Acceptance timeout expired');
                $count++;
            } catch (\Throwable $e) {
                $this->warn("Failed to expire order {$order->order_number}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$count} orders.");

        return self::SUCCESS;
    }
}
