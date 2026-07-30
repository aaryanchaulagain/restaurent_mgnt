<?php

namespace Tests\Feature\Hours;

use App\Models\Restaurant;
use App\Models\RestaurantSpecialHour;
use App\Services\Restaurant\RestaurantOpenStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantOpenStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_special_closure_reports_closed(): void
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'open-status',
            'legal_business_name' => 'Open Status Pty Ltd',
            'trading_name' => 'Open Status',
            'status' => 'active',
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
        ]);
        RestaurantSpecialHour::query()->create([
            'restaurant_id' => $restaurant->id,
            'date' => now('Australia/Sydney')->toDateString(),
            'is_closed' => true,
        ]);

        $service = app(RestaurantOpenStatusService::class);
        $this->assertFalse($service->isOpenNow($restaurant));
    }
}
