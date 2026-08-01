<?php

namespace App\Console\Commands;

use App\Services\Business\BusinessHierarchyMigrator;
use Illuminate\Console\Command;

class MigrateRestaurantsToBusinessHierarchy extends Command
{
    protected $signature = 'khana:migrate-business-hierarchy {--force : Run even if some restaurants already linked}';

    protected $description = 'Create Business + default Branch for every existing restaurant (Phase 1)';

    public function handle(BusinessHierarchyMigrator $migrator): int
    {
        $this->info('Migrating restaurants → businesses + branches…');
        $result = $migrator->migrateAll();
        $this->info("Migrated: {$result['migrated']}");
        $this->info("Skipped (already linked): {$result['skipped']}");

        return self::SUCCESS;
    }
}
