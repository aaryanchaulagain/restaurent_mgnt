<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicBranchResource;
use App\Http\Resources\Public\PublicBusinessResource;
use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Services\PublicCatalog\PublicBusinessBranchService;
use App\Services\PublicCatalog\PublicCatalogueService;
use App\Services\Restaurant\RestaurantOpenStatusService;
use App\Support\ApiResponse;

class PublicBusinessController extends Controller
{
    public function __construct(
        private readonly PublicBusinessBranchService $resolver,
        private readonly PublicCatalogueService $catalogue,
        private readonly RestaurantOpenStatusService $openStatus,
    ) {}

    public function show(string $businessSlug)
    {
        $business = $this->resolver->resolveBusiness($businessSlug);
        $branches = $this->resolver->listPublicBranches($business);
        $preferred = $this->resolver->preferredPublicBranch($business);

        return ApiResponse::success([
            'business' => (new PublicBusinessResource($business))->resolve(),
            'branches' => $branches->map(fn (Branch $branch) => $this->branchPayload($branch))->values(),
            'preferred_branch_public_id' => $preferred?->public_id,
            'branch_count' => $branches->count(),
        ]);
    }

    public function branches(string $businessSlug)
    {
        $business = $this->resolver->resolveBusiness($businessSlug);
        $branches = $this->resolver->listPublicBranches($business);

        return ApiResponse::success([
            'business' => (new PublicBusinessResource($business))->resolve(),
            'branches' => $branches->map(fn (Branch $branch) => $this->branchPayload($branch))->values(),
        ]);
    }

    public function showBranch(string $businessSlug, string $branchPublicId)
    {
        $business = $this->resolver->resolveBusiness($businessSlug);
        $branch = $this->resolver->resolvePublicBranch($business, $branchPublicId);
        $restaurant = $this->resolver->resolveLinkedRestaurant($branch);

        return ApiResponse::success([
            'business' => (new PublicBusinessResource($business))->resolve(),
            'branch' => $this->branchPayload($branch),
            'catalogue_restaurant_slug' => $restaurant->slug,
        ]);
    }

    public function branchMenu(string $businessSlug, string $branchPublicId)
    {
        $business = $this->resolver->resolveBusiness($businessSlug);
        $branch = $this->resolver->resolvePublicBranch($business, $branchPublicId);
        $restaurant = $this->resolver->resolveLinkedRestaurant($branch);

        $payload = $this->catalogue->menuPayload($restaurant);

        return ApiResponse::success(array_merge($payload, [
            'business' => [
                'slug' => $business->slug,
                'name' => $business->name,
            ],
            'branch' => [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
            ],
        ]));
    }

    private function branchPayload(Branch $branch): array
    {
        $restaurant = $branch->restaurant;
        $ops = [
            'is_open_now' => false,
            'next_opening_time' => null,
            'today_hours' => null,
        ];

        if ($restaurant instanceof Restaurant) {
            $ops = [
                'is_open_now' => $this->openStatus->isOpenNow($restaurant),
                'next_opening_time' => $this->openStatus->nextOpeningTime($restaurant),
                'today_hours' => $this->todayHours($restaurant),
            ];
        }

        return (new PublicBranchResource($branch, $ops))->resolve();
    }

    private function todayHours(Restaurant $restaurant): ?array
    {
        $tz = $restaurant->timezone ?: config('restaurant.default_timezone');
        $day = (int) now($tz)->dayOfWeek;
        $periods = RestaurantOpeningHour::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('day_of_week', $day)
            ->where('is_closed', false)
            ->get();

        if ($periods->isEmpty()) {
            return null;
        }

        return $periods->map(fn ($p) => [
            'opens_at' => $p->opens_at,
            'closes_at' => $p->closes_at,
            'service_type' => $p->service_type,
        ])->all();
    }
}
