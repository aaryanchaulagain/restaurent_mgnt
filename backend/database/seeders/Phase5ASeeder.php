<?php

namespace Database\Seeders;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Offer;
use App\Models\OfferTarget;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantServiceArea;
use App\Models\RestaurantSpecialHour;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Phase5ASeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRestaurants();
        $this->seedCarts();
    }

    private function seedRestaurants(): void
    {
        // 1. Active open restaurant with full menu
        $r1 = $this->restaurant('golden-wok', 'Golden Wok', RestaurantStatus::Active, true);
        $this->fullMenu($r1);
        $this->openAllWeek($r1);
        $this->serviceArea($r1, '2000');
        $this->activeOffer($r1);

        // 2. Active currently closed restaurant
        $r2 = $this->restaurant('night-owl', 'Night Owl Kitchen', RestaurantStatus::Active, true);
        $this->fullMenu($r2);
        RestaurantOpeningHour::query()->firstOrCreate(
            ['restaurant_id' => $r2->id, 'day_of_week' => now('Australia/Sydney')->dayOfWeek],
            ['opens_at' => '23:00', 'closes_at' => '23:59', 'is_closed' => false, 'service_type' => 'all']
        );

        // 3. Temporarily closed restaurant
        $r3 = $this->restaurant('temp-closed', 'Temp Closed Cafe', RestaurantStatus::Active, true);
        $r3->update(['temporarily_closed_until' => now()->addDays(3)]);
        $this->fullMenu($r3);

        // 4. Pending-setup restaurant
        $this->restaurant('pending-setup', 'Pending Bistro', RestaurantStatus::PendingSetup, false);

        // 5. Suspended restaurant
        $r5 = $this->restaurant('suspended-grill', 'Suspended Grill', RestaurantStatus::Active, true);
        $r5->update(['suspended_at' => now()]);

        // 6. Disabled restaurant
        $this->restaurant('disabled-diner', 'Disabled Diner', RestaurantStatus::Disabled, false);

        // 7. Restaurant with no menu
        $this->restaurant('no-menu', 'No Menu Place', RestaurantStatus::Active, true);

        // 8. Pickup-only restaurant
        $r8 = $this->restaurant('pickup-only', 'Pickup Palace', RestaurantStatus::Active, true);
        $r8->update(['pickup_enabled' => true, 'restaurant_delivery_enabled' => false]);
        $this->fullMenu($r8);
        $this->openAllWeek($r8);

        // 9. Postcode-delivery restaurant
        $r9 = $this->restaurant('postcode-delivery', 'Postcode Express', RestaurantStatus::Active, true);
        $r9->update(['restaurant_delivery_enabled' => true]);
        $this->fullMenu($r9);
        $this->openAllWeek($r9);
        $this->serviceArea($r9, '2000');
        $this->serviceArea($r9, '2010');

        // 10-14: restaurants with special features
        $rSoldOut = $this->restaurant('sold-out-items', 'SoldOut Sushi', RestaurantStatus::Active, true);
        $this->fullMenu($rSoldOut, soldOut: true);
        $this->openAllWeek($rSoldOut);

        $rVariant = $this->restaurant('variant-heavy', 'Variant Burgers', RestaurantStatus::Active, true);
        $this->fullMenu($rVariant, variants: true);
        $this->openAllWeek($rVariant);

        $rReqMod = $this->restaurant('required-mods', 'Modifier Meals', RestaurantStatus::Active, true);
        $this->fullMenu($rReqMod, requiredModifiers: true);
        $this->openAllWeek($rReqMod);

        $rOptMod = $this->restaurant('optional-mods', 'Extra Toppings', RestaurantStatus::Active, true);
        $this->fullMenu($rOptMod, optionalModifiers: true);
        $this->openAllWeek($rOptMod);

        $rAllergen = $this->restaurant('allergen-labelled', 'Allergen Aware', RestaurantStatus::Active, true);
        $this->fullMenu($rAllergen);
        $this->openAllWeek($rAllergen);

        // 15-17: offer scenarios
        $rFutureOffer = $this->restaurant('future-offer', 'Future Deals', RestaurantStatus::Active, true);
        $this->fullMenu($rFutureOffer);
        $this->openAllWeek($rFutureOffer);
        $this->futureOffer($rFutureOffer);

        $rExpiredOffer = $this->restaurant('expired-offer', 'Expired Promos', RestaurantStatus::Active, true);
        $this->fullMenu($rExpiredOffer);
        $this->openAllWeek($rExpiredOffer);
        $this->expiredOffer($rExpiredOffer);
    }

    private function seedCarts(): void
    {
        $customer = User::query()->where('email', 'customer@example.com')->first();
        if (! $customer) {
            return;
        }

        $r1 = Restaurant::query()->where('slug', 'golden-wok')->first();
        if (! $r1) {
            return;
        }

        $item = MenuItem::query()->where('restaurant_id', $r1->id)->where('is_active', true)->first();
        if (! $item) {
            return;
        }

        // Guest cart
        Cart::query()->firstOrCreate(['token_hash' => hash('sha256', 'demo-guest-token')], [
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $r1->id,
            'status' => 'active',
            'currency' => 'AUD',
            'expires_at' => now()->addDays(14),
        ]);

        // Customer cart
        $customerCart = Cart::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'status' => 'active'],
            [
                'public_id' => (string) Str::uuid(),
                'restaurant_id' => $r1->id,
                'currency' => 'AUD',
            ]
        );
        CartItem::query()->firstOrCreate(['cart_id' => $customerCart->id, 'menu_item_id' => $item->id], [
            'public_id' => (string) Str::uuid(),
            'quantity' => 2,
            'unit_price_snapshot_cents' => $item->base_price_cents,
            'estimated_total_cents' => $item->base_price_cents * 2,
        ]);

        // Customer address
        CustomerAddress::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'label' => 'Home'],
            [
                'public_id' => (string) Str::uuid(),
                'recipient_name' => 'Anisha Rai',
                'address_line_1' => '42 George Street',
                'suburb' => 'Sydney',
                'state' => 'NSW',
                'postcode' => '2000',
                'country' => 'AU',
                'is_default' => true,
            ]
        );
    }

    private function restaurant(string $slug, string $name, RestaurantStatus $status, bool $published): Restaurant
    {
        return Restaurant::query()->firstOrCreate(['slug' => $slug], [
            'public_id' => (string) Str::uuid(),
            'legal_business_name' => $name.' Pty Ltd',
            'trading_name' => $name,
            'status' => $status,
            'published_at' => $published ? now() : null,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'minimum_order_cents' => 2000,
        ]);
    }

    private function fullMenu(
        Restaurant $restaurant,
        bool $soldOut = false,
        bool $variants = false,
        bool $requiredModifiers = false,
        bool $optionalModifiers = false,
    ): void {
        $menu = Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            ['public_id' => (string) Str::uuid(), 'name' => 'Main', 'status' => 'active']
        );
        $cat = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Mains'],
            ['public_id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'is_active' => true]
        );

        $items = [
            ['name' => 'Chicken Katsu', 'slug' => 'chicken-katsu-'.$restaurant->slug, 'base_price_cents' => 1990],
            ['name' => 'Beef Burger', 'slug' => 'beef-burger-'.$restaurant->slug, 'base_price_cents' => 2490],
            ['name' => 'Garden Salad', 'slug' => 'garden-salad-'.$restaurant->slug, 'base_price_cents' => 1290],
        ];

        foreach ($items as $idx => $data) {
            $item = MenuItem::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'slug' => $data['slug']],
                array_merge($data, [
                    'public_id' => (string) Str::uuid(),
                    'restaurant_id' => $restaurant->id,
                    'menu_id' => $menu->id,
                    'menu_category_id' => $cat->id,
                    'is_active' => true,
                    'is_available' => $soldOut && $idx === 0 ? false : true,
                    'cost_price_cents' => (int) ($data['base_price_cents'] * 0.35),
                ])
            );

            if ($variants && $idx === 0) {
                foreach ([['Small', 1490], ['Regular', 1990], ['Large', 2490]] as $vi => $v) {
                    MenuItemVariant::query()->firstOrCreate(
                        ['menu_item_id' => $item->id, 'name' => $v[0]],
                        [
                            'public_id' => (string) Str::uuid(),
                            'restaurant_id' => $restaurant->id,
                            'price_cents' => $v[1],
                            'is_default' => $vi === 1,
                            'sort_order' => $vi,
                        ]
                    );
                }
            }

            if (($requiredModifiers || $optionalModifiers) && $idx === 0) {
                $group = ModifierGroup::query()->firstOrCreate(
                    ['restaurant_id' => $restaurant->id, 'name' => $requiredModifiers ? 'Spice Level' : 'Extras'],
                    [
                        'public_id' => (string) Str::uuid(),
                        'selection_type' => 'single',
                        'minimum_selections' => $requiredModifiers ? 1 : 0,
                        'maximum_selections' => 1,
                        'is_required' => $requiredModifiers,
                    ]
                );
                foreach ([['Mild', 0], ['Medium', 0], ['Hot', 100]] as $oi => $o) {
                    ModifierOption::query()->firstOrCreate(
                        ['modifier_group_id' => $group->id, 'name' => $o[0]],
                        [
                            'public_id' => (string) Str::uuid(),
                            'restaurant_id' => $restaurant->id,
                            'price_adjustment_cents' => $o[1],
                            'is_default' => $oi === 0,
                        ]
                    );
                }
                $item->modifierGroups()->syncWithoutDetaching([$group->id]);
            }
        }
    }

    private function openAllWeek(Restaurant $restaurant): void
    {
        for ($d = 0; $d < 7; $d++) {
            RestaurantOpeningHour::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'day_of_week' => $d, 'service_type' => 'all', 'is_closed' => false],
                ['opens_at' => '10:00', 'closes_at' => '22:00']
            );
        }
    }

    private function serviceArea(Restaurant $restaurant, string $postcode): void
    {
        RestaurantServiceArea::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'type' => 'postcode', 'postcode' => $postcode],
            ['is_active' => true]
        );
    }

    private function activeOffer(Restaurant $restaurant): void
    {
        Offer::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => '10% Off'],
            [
                'public_id' => (string) Str::uuid(),
                'offer_type' => 'percentage',
                'value' => 10,
                'minimum_order_cents' => 3000,
                'maximum_discount_cents' => 1000,
                'is_active' => true,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
            ]
        );
    }

    private function futureOffer(Restaurant $restaurant): void
    {
        Offer::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Coming Soon 20%'],
            [
                'public_id' => (string) Str::uuid(),
                'offer_type' => 'percentage',
                'value' => 20,
                'is_active' => true,
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->addMonth(),
            ]
        );
    }

    private function expiredOffer(Restaurant $restaurant): void
    {
        Offer::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Expired 5% Off'],
            [
                'public_id' => (string) Str::uuid(),
                'offer_type' => 'percentage',
                'value' => 5,
                'is_active' => true,
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->subDay(),
            ]
        );
    }
}
