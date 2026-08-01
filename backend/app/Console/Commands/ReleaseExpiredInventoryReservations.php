<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryReservationService;
use Illuminate\Console\Command;

class ReleaseExpiredInventoryReservations extends Command
{
    protected $signature = 'inventory:release-expired-reservations';

    protected $description = 'Release active inventory reservations past their expires_at timestamp.';

    public function handle(InventoryReservationService $reservations): int
    {
        $count = $reservations->releaseExpired();
        $this->info("Released {$count} expired inventory reservations.");

        return self::SUCCESS;
    }
}
