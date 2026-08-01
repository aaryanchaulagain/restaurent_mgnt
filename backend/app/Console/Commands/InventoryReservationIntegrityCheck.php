<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryReservationService;
use Illuminate\Console\Command;

class InventoryReservationIntegrityCheck extends Command
{
    protected $signature = 'inventory:reservation-integrity';

    protected $description = 'Report inventory reservation integrity issues (expired actives, orphans).';

    public function handle(InventoryReservationService $reservations): int
    {
        $report = $reservations->integrityReport();
        $this->table(
            ['Check', 'Count'],
            [
                ['active_without_order', $report['active_without_order']],
                ['active_past_expiry', $report['active_past_expiry']],
                ['consumed_with_active_sibling', $report['consumed_with_active_sibling']],
            ],
        );

        $issues = array_sum($report);
        if ($issues > 0) {
            $this->warn("Found {$issues} integrity issue(s).");

            return self::FAILURE;
        }

        $this->info('Inventory reservations look healthy.');

        return self::SUCCESS;
    }
}
