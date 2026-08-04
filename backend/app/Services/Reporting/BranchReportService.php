<?php

namespace App\Services\Reporting;

use App\Models\Branch;
use App\Models\MenuItemInventory;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only operational aggregates for authorized branches.
 */
class BranchReportService
{
    /**
     * @param  Collection<int, Branch>|list<Branch>  $branches
     * @return array<string, mixed>
     */
    public function summarizeBranches($branches, ReportDateRange $range, bool $includeFinance = false): array
    {
        $branches = Collection::wrap($branches)->values();
        if ($branches->isEmpty()) {
            return [
                'summary' => $this->emptySummary(),
                'branches' => [],
                'order_status_breakdown' => [],
                'fulfilment_breakdown' => [],
                'payment_breakdown' => $includeFinance ? $this->emptyPaymentBreakdown() : null,
            ];
        }

        $restaurantIds = $branches->pluck('restaurant_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        $orderRows = Order::query()
            ->select([
                'restaurant_id',
                'status',
                'fulfilment_type',
                'payment_method',
                'payment_status',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(total_cents), 0) as gross_order_value_cents'),
                DB::raw('COALESCE(AVG(total_cents), 0) as average_order_value_cents'),
            ])
            ->whereIn('restaurant_id', $restaurantIds)
            ->whereBetween('placed_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('restaurant_id', 'status', 'fulfilment_type', 'payment_method', 'payment_status')
            ->get();

        $driver = DB::connection()->getDriverName();
        $acceptExpr = $driver === 'sqlite'
            ? 'AVG(CASE WHEN accepted_at IS NOT NULL AND placed_at IS NOT NULL THEN (julianday(accepted_at) - julianday(placed_at)) * 86400 END)'
            : 'AVG(CASE WHEN accepted_at IS NOT NULL AND placed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, placed_at, accepted_at) END)';
        $prepExpr = $driver === 'sqlite'
            ? 'AVG(CASE WHEN ready_at IS NOT NULL AND accepted_at IS NOT NULL THEN (julianday(ready_at) - julianday(accepted_at)) * 86400 END)'
            : 'AVG(CASE WHEN ready_at IS NOT NULL AND accepted_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, accepted_at, ready_at) END)';

        $durationRows = Order::query()
            ->select([
                'restaurant_id',
                DB::raw("{$acceptExpr} as avg_accept_seconds"),
                DB::raw("{$prepExpr} as avg_prep_seconds"),
            ])
            ->whereIn('restaurant_id', $restaurantIds)
            ->whereBetween('placed_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('restaurant_id')
            ->get()
            ->keyBy('restaurant_id');

        $paidByRestaurant = [];
        $refundedByRestaurant = [];
        $paymentStatusBreakdown = [];
        if ($includeFinance) {
            $paymentRows = Payment::query()
                ->select([
                    'restaurant_id',
                    'status',
                    DB::raw('COALESCE(SUM(amount_cents), 0) as amount_cents'),
                    DB::raw('COALESCE(SUM(amount_refunded_cents), 0) as refunded_cents'),
                    DB::raw('COUNT(*) as payment_count'),
                ])
                ->whereIn('restaurant_id', $restaurantIds)
                ->where(function ($q) use ($range) {
                    $q->whereBetween('paid_at', [$range->startUtc(), $range->endUtc()])
                        ->orWhere(function ($q2) use ($range) {
                            $q2->whereNull('paid_at')
                                ->whereBetween('created_at', [$range->startUtc(), $range->endUtc()]);
                        });
                })
                ->groupBy('restaurant_id', 'status')
                ->get();

            foreach ($paymentRows as $row) {
                $rid = (int) $row->restaurant_id;
                $paymentStatusBreakdown[$row->status] = ($paymentStatusBreakdown[$row->status] ?? 0) + (int) $row->payment_count;
                if (in_array($row->status, ['paid', 'partially_refunded', 'refunded'], true)) {
                    $paidByRestaurant[$rid] = ($paidByRestaurant[$rid] ?? 0) + (int) $row->amount_cents;
                }
                $refundedByRestaurant[$rid] = ($refundedByRestaurant[$rid] ?? 0) + (int) $row->refunded_cents;
            }
        }

        $inventoryByRestaurant = $this->inventoryCounts($restaurantIds);

        $perBranch = [];
        $statusTotals = [];
        $fulfilmentTotals = [];
        $summary = $this->emptySummary();

        foreach ($branches as $branch) {
            $rid = (int) $branch->restaurant_id;
            $metrics = [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
                'status' => $branch->status,
                'accepting_orders' => (bool) $branch->accepting_orders,
                'total_orders' => 0,
                'completed_orders' => 0,
                'cancelled_orders' => 0,
                'rejected_orders' => 0,
                'expired_orders' => 0,
                'active_orders' => 0,
                'gross_order_value_cents' => 0,
                'average_order_value_cents' => 0,
                'paid_amount_cents' => $includeFinance ? ($paidByRestaurant[$rid] ?? 0) : null,
                'refunded_amount_cents' => $includeFinance ? ($refundedByRestaurant[$rid] ?? 0) : null,
                'low_stock_count' => $inventoryByRestaurant[$rid]['low_stock'] ?? 0,
                'out_of_stock_count' => $inventoryByRestaurant[$rid]['out_of_stock'] ?? 0,
                'tracked_inventory_count' => $inventoryByRestaurant[$rid]['tracked'] ?? 0,
                'average_acceptance_seconds' => null,
                'average_preparation_seconds' => null,
            ];

            $branchGross = 0;
            $branchOrders = 0;
            foreach ($orderRows->where('restaurant_id', $rid) as $row) {
                $count = (int) $row->order_count;
                $gross = (int) $row->gross_order_value_cents;
                $metrics['total_orders'] += $count;
                $branchOrders += $count;
                $branchGross += $gross;
                $statusTotals[$row->status] = ($statusTotals[$row->status] ?? 0) + $count;
                $fulfilmentTotals[$row->fulfilment_type] = ($fulfilmentTotals[$row->fulfilment_type] ?? 0) + $count;

                match ($row->status) {
                    'completed_pickup' => $metrics['completed_orders'] += $count,
                    'cancelled' => $metrics['cancelled_orders'] += $count,
                    'rejected' => $metrics['rejected_orders'] += $count,
                    'expired' => $metrics['expired_orders'] += $count,
                    'pending_payment', 'awaiting_restaurant', 'accepted', 'preparing', 'ready_for_pickup' => $metrics['active_orders'] += $count,
                    default => null,
                };
            }
            $metrics['gross_order_value_cents'] = $branchGross;
            $metrics['average_order_value_cents'] = $branchOrders > 0
                ? (int) round($branchGross / $branchOrders)
                : 0;

            $dur = $durationRows->get($rid);
            if ($dur) {
                $accept = $dur->avg_accept_seconds;
                $prep = $dur->avg_prep_seconds;
                $metrics['average_acceptance_seconds'] = $accept !== null ? (int) round((float) $accept) : null;
                $metrics['average_preparation_seconds'] = $prep !== null ? (int) round((float) $prep) : null;
            }

            $summary['total_orders'] += $metrics['total_orders'];
            $summary['completed_orders'] += $metrics['completed_orders'];
            $summary['cancelled_orders'] += $metrics['cancelled_orders'];
            $summary['rejected_orders'] += $metrics['rejected_orders'];
            $summary['expired_orders'] += $metrics['expired_orders'];
            $summary['active_orders'] += $metrics['active_orders'];
            $summary['gross_order_value_cents'] += $metrics['gross_order_value_cents'];
            $summary['low_stock_count'] += $metrics['low_stock_count'];
            $summary['out_of_stock_count'] += $metrics['out_of_stock_count'];
            if ($includeFinance) {
                $summary['paid_amount_cents'] += $metrics['paid_amount_cents'] ?? 0;
                $summary['refunded_amount_cents'] += $metrics['refunded_amount_cents'] ?? 0;
            }

            $perBranch[] = $metrics;
        }

        usort($perBranch, function (array $a, array $b) {
            if ($a['gross_order_value_cents'] !== $b['gross_order_value_cents']) {
                return $b['gross_order_value_cents'] <=> $a['gross_order_value_cents'];
            }
            if ($a['total_orders'] !== $b['total_orders']) {
                return $b['total_orders'] <=> $a['total_orders'];
            }

            return strcmp($a['name'], $b['name']);
        });

        $summary['average_order_value_cents'] = $summary['total_orders'] > 0
            ? (int) round($summary['gross_order_value_cents'] / $summary['total_orders'])
            : 0;

        if (! $includeFinance) {
            $summary['paid_amount_cents'] = null;
            $summary['refunded_amount_cents'] = null;
        }

        return [
            'summary' => $summary,
            'branches' => $perBranch,
            'order_status_breakdown' => collect($statusTotals)->map(fn ($c, $s) => [
                'status' => $s,
                'count' => $c,
            ])->values()->all(),
            'fulfilment_breakdown' => collect($fulfilmentTotals)->map(fn ($c, $t) => [
                'fulfilment_type' => $t,
                'count' => $c,
            ])->values()->all(),
            'payment_breakdown' => $includeFinance ? [
                'by_status' => collect($paymentStatusBreakdown)->map(fn ($c, $s) => [
                    'status' => $s,
                    'count' => $c,
                ])->values()->all(),
                'paid_amount_cents' => $summary['paid_amount_cents'],
                'refunded_amount_cents' => $summary['refunded_amount_cents'],
            ] : null,
        ];
    }

    public function inventoryReport(Branch $branch): array
    {
        $rid = (int) $branch->restaurant_id;
        $counts = $this->inventoryCounts([$rid])[$rid] ?? [
            'tracked' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0,
            'availability_only' => 0,
        ];

        return [
            'branch' => [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
            ],
            'tracked_inventory_count' => $counts['tracked'],
            'low_stock_count' => $counts['low_stock'],
            'out_of_stock_count' => $counts['out_of_stock'],
            'availability_only_count' => $counts['availability_only'],
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'total_orders' => 0,
            'completed_orders' => 0,
            'cancelled_orders' => 0,
            'rejected_orders' => 0,
            'expired_orders' => 0,
            'active_orders' => 0,
            'gross_order_value_cents' => 0,
            'paid_amount_cents' => 0,
            'refunded_amount_cents' => 0,
            'average_order_value_cents' => 0,
            'low_stock_count' => 0,
            'out_of_stock_count' => 0,
        ];
    }

    /** @return array{by_status: list<array{status: string, count: int}>, paid_amount_cents: int, refunded_amount_cents: int} */
    private function emptyPaymentBreakdown(): array
    {
        return [
            'by_status' => [],
            'paid_amount_cents' => 0,
            'refunded_amount_cents' => 0,
        ];
    }

    /**
     * @param  list<int>  $restaurantIds
     * @return array<int, array{tracked: int, low_stock: int, out_of_stock: int, availability_only: int}>
     */
    private function inventoryCounts(array $restaurantIds): array
    {
        $out = [];
        foreach ($restaurantIds as $id) {
            $out[$id] = ['tracked' => 0, 'low_stock' => 0, 'out_of_stock' => 0, 'availability_only' => 0];
        }

        if ($restaurantIds === []) {
            return $out;
        }

        $rows = MenuItemInventory::query()
            ->whereIn('restaurant_id', $restaurantIds)
            ->get(['restaurant_id', 'track_stock', 'quantity_on_hand', 'low_stock_threshold', 'force_unavailable']);

        foreach ($rows as $row) {
            $rid = (int) $row->restaurant_id;
            if (! isset($out[$rid])) {
                continue;
            }
            if (! $row->track_stock) {
                $out[$rid]['availability_only']++;
                continue;
            }
            $out[$rid]['tracked']++;
            $qty = (int) $row->quantity_on_hand;
            $threshold = $row->low_stock_threshold !== null ? (int) $row->low_stock_threshold : null;
            if ($row->force_unavailable || $qty <= 0) {
                $out[$rid]['out_of_stock']++;
            } elseif ($threshold !== null && $qty <= $threshold) {
                $out[$rid]['low_stock']++;
            }
        }

        return $out;
    }
}
