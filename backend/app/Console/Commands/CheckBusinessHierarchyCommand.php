<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Restaurant;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BusinessRoles;
use Illuminate\Console\Command;

class CheckBusinessHierarchyCommand extends Command
{
    protected $signature = 'khana:check-business-hierarchy {--repair : Fix unambiguous missing business/branch links}';

    protected $description = 'Verify Business → Branch → Restaurant hierarchy consistency';

    public function handle(BusinessHierarchyMigrator $migrator): int
    {
        $issues = [];

        $restaurantsWithoutBusiness = Restaurant::query()->withTrashed()->whereNull('business_id')->count();
        $restaurantsWithoutBranch = Restaurant::query()->withTrashed()->whereNull('branch_id')->count();
        $branchesWithoutRestaurant = Branch::query()->withTrashed()->whereNull('restaurant_id')->count();
        $businessesWithoutOwner = Business::query()->whereNull('owner_user_id')->count();

        if ($restaurantsWithoutBusiness) {
            $issues[] = "Restaurants without business_id: {$restaurantsWithoutBusiness}";
        }
        if ($restaurantsWithoutBranch) {
            $issues[] = "Restaurants without branch_id: {$restaurantsWithoutBranch}";
        }
        if ($branchesWithoutRestaurant) {
            $issues[] = "Branches without restaurant_id: {$branchesWithoutRestaurant}";
        }
        if ($businessesWithoutOwner) {
            $issues[] = "Businesses without owner_user_id: {$businessesWithoutOwner}";
        }

        $mismatch = 0;
        Branch::query()->with(['restaurant', 'business'])->chunkById(100, function ($branches) use (&$mismatch, &$issues) {
            foreach ($branches as $branch) {
                $restaurant = $branch->restaurant;
                if (! $restaurant) {
                    continue;
                }
                if ((int) $restaurant->branch_id !== (int) $branch->id
                    || (int) $restaurant->business_id !== (int) $branch->business_id
                    || (int) $branch->restaurant_id !== (int) $restaurant->id) {
                    $mismatch++;
                    $issues[] = "Hierarchy mismatch branch #{$branch->id} / restaurant #{$restaurant->id}";
                }
            }
        });

        $branchesWithoutManager = 0;
        Branch::query()->each(function (Branch $branch) use (&$branchesWithoutManager, &$issues) {
            $hasManager = BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'active')
                ->where('role', BusinessRoles::BRANCH_MANAGER)
                ->exists();
            $hasBusinessManager = BusinessUser::query()
                ->where('business_id', $branch->business_id)
                ->where('status', 'active')
                ->whereIn('role', BusinessRoles::businessManagers())
                ->exists();
            if (! $hasManager && ! $hasBusinessManager) {
                $branchesWithoutManager++;
                $issues[] = "Branch #{$branch->id} has no manager";
            }
        });

        if ($this->option('repair')) {
            $result = $migrator->migrateAll();
            $this->info("Repair migrateAll → migrated {$result['migrated']}, skipped {$result['skipped']}");
        }

        if ($issues === []) {
            $this->info('Hierarchy OK.');

            return self::SUCCESS;
        }

        $this->warn('Hierarchy issues found:');
        foreach ($issues as $issue) {
            $this->line(' - '.$issue);
        }

        return self::FAILURE;
    }
}
