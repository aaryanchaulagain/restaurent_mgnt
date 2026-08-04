<?php

namespace App\Console\Commands;

use App\Services\Order\OrderTenantIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class OrderTenantIntegrityCommand extends Command
{
    protected $signature = 'orders:tenant-integrity
        {--order= : Limit to a single order public ID}
        {--json : Emit JSON}
        {--include-demo : Include demo/seed findings (default true)}
        {--exclude-demo : Hide deterministic demo/seed findings}
        {--repair : Attempt a confirmed safe repair}
        {--confirm= : Order public ID that must match --order for repair}';

    protected $description = 'Audit historical order↔restaurant↔branch↔business integrity (read-only by default)';

    public function handle(OrderTenantIntegrityService $service): int
    {
        $orderPublicId = $this->option('order') ? (string) $this->option('order') : null;
        $includeDemo = ! (bool) $this->option('exclude-demo');
        if ($this->option('include-demo')) {
            $includeDemo = true;
        }

        if ($this->option('repair')) {
            $confirm = (string) ($this->option('confirm') ?? '');
            if ($orderPublicId === null || $confirm === '' || ! hash_equals($orderPublicId, $confirm)) {
                $this->error('Repair requires matching --order={publicId} and --confirm={publicId}.');

                return self::FAILURE;
            }

            $result = $service->repairConfirmed($orderPublicId, null, (string) Str::uuid());
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } elseif ($result['ok']) {
                $this->info($result['message']);
            } else {
                $this->error(($result['code'] ?? 'ERROR').': '.($result['message'] ?? 'Repair failed'));
            }

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $report = $service->audit($orderPublicId, $includeDemo);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Order tenant integrity (read-only)');
        $this->table(['classification', 'count'], collect($report['summary'])->map(
            fn ($count, $class) => [$class, $count]
        )->values()->all());

        if ($report['findings'] === [] && $report['payment_orphans'] === [] && $report['reservation_orphans'] === []) {
            $this->info('No integrity findings.');

            return self::SUCCESS;
        }

        foreach ($report['findings'] as $row) {
            $this->line('');
            $this->warn("{$row['classification']} · {$row['order_number']} · {$row['order_public_id']}");
            $this->line("  status={$row['status']} payment_status={$row['payment_status']} total_cents={$row['total_cents']}");
            $this->line('  restaurant_slug='.($row['restaurant_slug'] ?? 'null')
                .' soft_deleted='.(($row['restaurant_soft_deleted'] ?? false) ? 'yes' : 'no')
                .' branch='.($row['branch_public_id'] ?? 'null')
                .' business='.($row['business_public_id'] ?? 'null'));
            $this->line("  payments={$row['payment_count']} payment_total_cents={$row['payment_total_cents']} reservations={$row['reservation_count']}");
            $this->line('  demo/seed='.(($row['is_demo_or_seed'] ?? false) ? 'yes' : 'no')
                .' financially_active='.(($row['financially_active'] ?? false) ? 'yes' : 'no'));
            $this->line("  action={$row['proposed_action']} confidence={$row['confidence']} repair_safe=".(($row['repair_safe'] ?? false) ? 'yes' : 'no'));
            $this->line('  reason='.$row['reason']);
        }

        foreach ($report['payment_orphans'] as $row) {
            $this->warn("PAYMENT_ORPHAN · {$row['payment_public_id']}: {$row['reason']}");
        }
        foreach ($report['reservation_orphans'] as $row) {
            $this->warn("RESERVATION_ORPHAN · {$row['reservation_public_id']}: {$row['reason']}");
        }

        $this->line('');
        $this->comment('No data was modified. Safe repairs require --repair --order=... --confirm=...');

        return self::SUCCESS;
    }
}
