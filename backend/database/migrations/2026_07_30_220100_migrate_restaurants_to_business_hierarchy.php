<?php

use App\Services\Business\BusinessHierarchyMigrator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BusinessHierarchyMigrator::class)->migrateAll();
    }

    public function down(): void
    {
        // Schema rollback is handled by 2026_07_30_220000_create_business_branch_hierarchy_tables.
        // Data reverse is intentionally not automatic to avoid deleting businesses that may have
        // been created after this migration ran.
    }
};
