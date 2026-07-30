<?php

namespace App\Services\Restaurant;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantUser;
use Illuminate\Support\Collection;

class RestaurantSetupChecklistService
{
    /**
     * @return array{completion_percentage: int, can_activate: bool, completed: array<int, string>, missing: array<int, string>}
     */
    public function evaluate(Restaurant $restaurant): array
    {
        $checks = [
            'trading_name' => filled($restaurant->trading_name),
            'description' => filled($restaurant->description),
            'business_email' => filled($restaurant->business_email),
            'business_phone' => filled($restaurant->business_phone),
            'primary_address' => RestaurantAddress::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_primary', true)
                ->exists(),
            'logo' => filled($restaurant->logo_path),
            'cover_image' => filled($restaurant->cover_image_path),
            'primary_cuisine' => $restaurant->primary_cuisine_id !== null
                || $restaurant->cuisines()->wherePivot('is_primary', true)->exists(),
            'service_type' => $restaurant->pickup_enabled
                || $restaurant->restaurant_delivery_enabled
                || $restaurant->dine_in_enabled,
            'opening_hours' => RestaurantOpeningHour::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_closed', false)
                ->exists(),
            'menu_category' => MenuCategory::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)
                ->exists(),
            'menu_item' => MenuItem::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)
                ->exists(),
            'currency' => filled($restaurant->currency),
            'timezone' => filled($restaurant->timezone),
            'commission_accepted' => RestaurantCommissionAgreement::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'accepted')
                ->exists(),
            'restaurant_owner' => RestaurantUser::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('slug', 'restaurant_owner'))
                ->exists(),
        ];

        $completed = collect($checks)->filter()->keys()->values()->all();
        $missing = collect($checks)->reject()->keys()->values()->all();
        $total = count($checks);
        $pct = $total > 0 ? (int) round((count($completed) / $total) * 100) : 0;

        $canActivate = $missing === []
            && $restaurant->status === RestaurantStatus::PendingSetup
            && ! $restaurant->suspended_at;

        return [
            'completion_percentage' => $pct,
            'can_activate' => $canActivate,
            'completed' => $completed,
            'missing' => $missing,
        ];
    }
}
