<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Support\BranchStatuses;
use Illuminate\Console\Command;

class ReportingIntegrityCommand extends Command
{
    protected $signature = 'reporting:integrity';

    protected $description = 'Report integrity issues affecting operational reporting (read-only)';

    public function handle(): int
    {
        $issues = [];

        Branch::query()->with(['restaurant'])->chunkById(100, function ($branches) use (&$issues) {
            foreach ($branches as $branch) {
                if (in_array($branch->status, [BranchStatuses::ACTIVE, BranchStatuses::PAUSED], true)
                    && ! $branch->restaurant_id) {
                    $issues[] = "branch:{$branch->public_id} active/paused without linked restaurant";
                }
                if ($branch->restaurant) {
                    if ((int) $branch->restaurant->branch_id !== (int) $branch->id) {
                        $issues[] = "branch:{$branch->public_id} restaurant mutual-link mismatch";
                    }
                    if ($branch->restaurant->business_id !== null
                        && (int) $branch->restaurant->business_id !== (int) $branch->business_id) {
                        $issues[] = "branch:{$branch->public_id} restaurant business mismatch";
                    }
                }
                if ($branch->timezone && ! in_array($branch->timezone, timezone_identifiers_list(), true)) {
                    $issues[] = "branch:{$branch->public_id} invalid timezone";
                }
            }
        });

        Order::query()->latest('id')->limit(500)->get()->each(function (Order $order) use (&$issues) {
            $restaurant = Restaurant::withTrashed()->find($order->restaurant_id);
            if (! $restaurant) {
                $issues[] = "order:{$order->public_id} restaurant physically missing (ORDER_RESTAURANT_MISSING)";

                return;
            }
            if ($restaurant->trashed()) {
                $issues[] = "order:{$order->public_id} restaurant soft-deleted slug={$restaurant->slug} (ORDER_RESTAURANT_SOFT_DELETED)";
            }
            if ($restaurant->branch_id === null
                && ! Branch::withTrashed()->where('restaurant_id', $restaurant->id)->exists()) {
                $issues[] = "order:{$order->public_id} restaurant has no branch";
            }
            if ($order->total_cents === null) {
                $issues[] = "order:{$order->public_id} missing total snapshot";
            }
            if ($order->total_cents < 0 || $order->subtotal_cents < 0) {
                $issues[] = "order:{$order->public_id} negative financial amount";
            }
            if ($order->status === 'completed_pickup' && $order->completed_at === null) {
                $issues[] = "order:{$order->public_id} completed without completed_at";
            }
            if ($order->accepted_at && $order->placed_at && $order->accepted_at->lt($order->placed_at)) {
                $issues[] = "order:{$order->public_id} accepted_at before placed_at";
            }
        });

        Payment::query()->latest('id')->limit(500)->get()->each(function (Payment $payment) use (&$issues) {
            if (! Order::query()->whereKey($payment->order_id)->exists()) {
                $issues[] = "payment:{$payment->public_id} missing order";
            }
            if ($payment->amount_cents < 0 || ($payment->amount_refunded_cents ?? 0) < 0) {
                $issues[] = "payment:{$payment->public_id} negative amount";
            }
        });

        if ($issues === []) {
            $this->info('No reporting integrity issues found.');

            return self::SUCCESS;
        }

        $this->warn(count($issues).' integrity issue(s):');
        foreach ($issues as $issue) {
            $this->line(' - '.$issue);
        }

        return self::SUCCESS;
    }
}
