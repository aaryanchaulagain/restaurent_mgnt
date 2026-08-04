<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Services\Cart\CartBranchContext;
use Illuminate\Console\Command;

/**
 * Read-only integrity report for cart/order branch↔restaurant consistency.
 */
class CartBranchIntegrityCommand extends Command
{
    protected $signature = 'cart:branch-integrity {--repair-empty : Abandon empty active carts with broken restaurant links}';

    protected $description = 'Report cart/order branch-restaurant integrity issues (read-only by default)';

    public function handle(CartBranchContext $branchContext): int
    {
        $issues = [];

        Cart::query()->where('status', 'active')->with(['restaurant.branch', 'items'])->chunkById(100, function ($carts) use (&$issues, $branchContext) {
            foreach ($carts as $cart) {
                if (! $cart->restaurant_id) {
                    $issues[] = "cart:{$cart->public_id} missing restaurant_id";
                    continue;
                }
                $restaurant = $cart->restaurant;
                if (! $restaurant) {
                    $issues[] = "cart:{$cart->public_id} restaurant missing";
                    continue;
                }
                if ($restaurant->branch_id) {
                    $branch = $restaurant->branch;
                    if (! $branch) {
                        $issues[] = "cart:{$cart->public_id} restaurant has branch_id but branch missing";
                    } elseif (! $branchContext->linksAreConsistent($branch, $restaurant)) {
                        $issues[] = "cart:{$cart->public_id} branch/restaurant mutual-link mismatch";
                    }
                }
                foreach ($cart->items as $item) {
                    $itemRestaurantId = CartItem::query()
                        ->whereKey($item->id)
                        ->join('menu_items', 'menu_items.id', '=', 'cart_items.menu_item_id')
                        ->value('menu_items.restaurant_id');
                    if ((int) $itemRestaurantId !== (int) $cart->restaurant_id) {
                        $issues[] = "cart_item:{$item->public_id} belongs to another restaurant";
                    }
                }
                if ($cart->items->isEmpty() && $this->option('repair-empty')) {
                    $cart->update(['status' => 'abandoned']);
                    $this->warn("Repaired empty cart {$cart->public_id} → abandoned");
                }
            }
        });

        Order::query()->with(['restaurant.branch'])->latest('id')->limit(500)->get()->each(function (Order $order) use (&$issues, $branchContext) {
            $restaurant = $order->restaurant;
            if (! $restaurant) {
                $issues[] = "order:{$order->public_id} restaurant missing";

                return;
            }
            if ($restaurant->branch_id && $restaurant->branch
                && ! $branchContext->linksAreConsistent($restaurant->branch, $restaurant)) {
                $issues[] = "order:{$order->public_id} branch/restaurant mutual-link mismatch";
            }
        });

        if (\Illuminate\Support\Facades\Schema::hasTable('inventory_reservations')) {
            \Illuminate\Support\Facades\DB::table('inventory_reservations')
                ->orderByDesc('id')
                ->limit(500)
                ->get()
                ->each(function ($reservation) use (&$issues) {
                    $orderRestaurantId = Order::query()->whereKey($reservation->order_id)->value('restaurant_id');
                    if ($orderRestaurantId && (int) $reservation->restaurant_id !== (int) $orderRestaurantId) {
                        $issues[] = "reservation:{$reservation->id} inventory restaurant mismatch vs order";
                    }
                });
        }

        if ($issues === []) {
            $this->info('No cart/order branch integrity issues found.');

            return self::SUCCESS;
        }

        $this->error(count($issues).' integrity issue(s):');
        foreach ($issues as $issue) {
            $this->line(' - '.$issue);
        }

        return self::FAILURE;
    }
}
