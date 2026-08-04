<?php

namespace App\Console\Commands;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Business;
use App\Models\Restaurant;
use App\Support\DemoSeededRestaurantSlugs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Archives confirmed demo-seeded partners by exact restaurant slug.
 * Does not hard-delete orders, payments, or user-created partners.
 */
class ArchiveDemoSeededPartners extends Command
{
    protected $signature = 'demo:archive-seeded-partners {--dry-run : List targets without changing data}';

    protected $description = 'Archive confirmed Phase4/5/6/Sold demo restaurants by exact slug; preserve Suvakamana and user-created partners.';

    /** Never archive these (system demo partner + user-created). */
    private const PRESERVE_RESTAURANT_SLUGS = [
        'suvakamana-restaurant',
        'copy-grocery',
        'aryan-butchery',
        'aryan-cake',
        'aryan-cake-222',
        'aryan-gro',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targets = Restaurant::query()
            ->withTrashed()
            ->whereIn('slug', DemoSeededRestaurantSlugs::all())
            ->whereNotIn('slug', self::PRESERVE_RESTAURANT_SLUGS)
            ->get();

        if ($targets->isEmpty()) {
            $this->info('No confirmed demo-seeded restaurants found to archive.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'trading_name', 'business_id', 'deleted'],
            $targets->map(fn (Restaurant $r) => [
                $r->id,
                $r->slug,
                $r->trading_name,
                $r->business_id,
                $r->trashed() ? 'yes' : 'no',
            ])->all(),
        );

        if ($dryRun) {
            $this->warn('Dry run only — no changes applied.');

            return self::SUCCESS;
        }

        $archived = 0;
        DB::transaction(function () use ($targets, &$archived) {
            foreach ($targets as $restaurant) {
                if (! $restaurant->trashed()) {
                    $restaurant->forceFill([
                        'status' => RestaurantStatus::Disabled,
                        'published_at' => null,
                        'accepting_orders' => false,
                        'suspended_at' => $restaurant->suspended_at ?? now(),
                        'suspension_reason' => $restaurant->suspension_reason ?: 'Archived demo-seeded partner',
                    ])->save();
                    $restaurant->delete();
                    $archived++;
                }

                if ($restaurant->business_id) {
                    $stillPublic = Restaurant::query()
                        ->where('business_id', $restaurant->business_id)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (! $stillPublic) {
                        Business::query()->whereKey($restaurant->business_id)->update([
                            'status' => 'suspended',
                            'suspended_at' => now(),
                            'suspension_reason' => 'Archived demo-seeded partner',
                        ]);
                    }
                }
            }
        });

        $this->info("Archived {$archived} demo restaurant(s). Orders/payments left intact.");

        return self::SUCCESS;
    }
}
