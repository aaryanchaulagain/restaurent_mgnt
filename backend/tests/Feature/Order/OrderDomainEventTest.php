<?php

namespace Tests\Feature\Order;

use App\Enums\Partner\RestaurantStatus;
use App\Events\Order\OrderAccepted;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderCompleted;
use App\Events\Order\OrderExpired;
use App\Events\Order\OrderPlaced;
use App\Events\Order\OrderPreparing;
use App\Events\Order\OrderReady;
use App\Events\Order\OrderRejected;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutQuote;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderDomainEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_order_placed_dispatched_after_successful_placement(): void
    {
        Event::fake([OrderPlaced::class]);

        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'event-placed',
            'payment_method' => 'cash',
        ])->assertCreated();

        Event::assertDispatched(OrderPlaced::class);
    }

    public function test_order_placed_not_dispatched_on_failed_placement(): void
    {
        Event::fake([OrderPlaced::class]);

        [$user, , , $quote] = $this->createCheckoutQuote();
        $quote->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'event-failed',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_order_accepted_dispatched_after_transition(): void
    {
        Event::fake([OrderAccepted::class]);
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();

        Event::assertDispatched(OrderAccepted::class);
    }

    public function test_order_rejected_dispatched_after_transition(): void
    {
        Event::fake([OrderRejected::class]);
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/reject", [
            'reason' => 'item_unavailable',
            'explanation' => 'Sold out',
        ])->assertOk();

        Event::assertDispatched(OrderRejected::class);
    }

    public function test_order_preparing_dispatched_after_transition(): void
    {
        Event::fake([OrderPreparing::class]);
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")->assertOk();

        Event::assertDispatched(OrderPreparing::class);
    }

    public function test_order_ready_dispatched_after_transition(): void
    {
        Event::fake([OrderReady::class]);
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/mark-ready")->assertOk();

        Event::assertDispatched(OrderReady::class);
    }

    public function test_order_completed_dispatched_after_transition(): void
    {
        Event::fake([OrderCompleted::class]);
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/mark-ready")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/complete-pickup")->assertOk();

        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_order_cancelled_dispatched_after_customer_cancel(): void
    {
        Event::fake([OrderCancelled::class]);
        [$user, $order] = $this->placeCustomerOrderWithUser();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/orders/{$order->public_id}/cancel", [
            'reason' => 'Changed plans',
        ])->assertOk();

        Event::assertDispatched(OrderCancelled::class);
    }

    public function test_order_expired_dispatched_after_expire_command(): void
    {
        Event::fake([OrderExpired::class]);
        [, $order] = $this->placeCustomerOrderWithUser();
        $order->update(['expires_at' => now()->subMinute()]);

        Artisan::call('orders:expire-unaccepted');

        Event::assertDispatched(OrderExpired::class);
    }

    /** @return array{0: User|null, 1: Restaurant, 2: Cart, 3: CheckoutQuote, 4: MenuItem} */
    private function createCheckoutQuote(bool $guest = false): array
    {
        $user = $guest ? null : $this->customer();

        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'event-'.Str::lower(Str::random(8)),
            'legal_business_name' => 'Event Test Pty Ltd',
            'trading_name' => 'Event Kitchen',
            'ownership_type' => 'first_party',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
        ]);

        $menu = Menu::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Main',
            'status' => 'active',
            'is_default' => true,
        ]);

        $category = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);

        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $category->id,
            'name' => 'Event Item',
            'slug' => 'event-item-'.Str::lower(Str::random(6)),
            'base_price_cents' => 1250,
            'is_active' => true,
            'is_available' => true,
        ]);

        $cart = Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user?->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'active',
            'currency' => 'AUD',
            'version' => 1,
        ]);

        CartItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'menu_item_id' => $item->id,
            'quantity' => 2,
            'unit_price_snapshot_cents' => 1250,
            'estimated_total_cents' => 2500,
        ]);

        $quote = CheckoutQuote::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'customer_id' => $user?->id,
            'restaurant_id' => $restaurant->id,
            'fulfilment_type' => 'pickup',
            'pricing_snapshot' => [
                'subtotal_cents' => 2500,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'service_fee_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_before_delivery_cents' => 2500,
            ],
            'warnings' => [],
            'expires_at' => now()->addMinutes(15),
            'status' => 'active',
        ]);

        return [$user, $restaurant, $cart, $quote, $item];
    }

    /** @return array{0: User, 1: Restaurant} */
    private function restaurantOwner(Restaurant $restaurant): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();

        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        $user->load('roles.permissions');

        return [$user, $restaurant];
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->load('roles.permissions');

        return $user;
    }

    private function placeCustomerOrder(): Order
    {
        [, $order] = $this->placeCustomerOrderWithUser();

        return $order;
    }

    /** @return array{0: User, 1: Order} */
    private function placeCustomerOrderWithUser(): array
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'event-'.Str::lower(Str::random(12)),
            'payment_method' => 'cash',
        ])->assertCreated();

        $order = Order::query()
            ->where('order_number', $response->json('data.order.order_number'))
            ->firstOrFail();

        return [$user, $order];
    }

    private function seedPermissions(): void
    {
        $permissionSlugs = array_unique(array_merge(
            config('suvakamana.permissions'),
            [
                'place_order',
                'view_own_orders',
                'cancel_own_order',
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'reject_restaurant_orders',
                'prepare_restaurant_orders',
                'complete_restaurant_orders',
            ]
        ));

        foreach ($permissionSlugs as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $customerRole = Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        $ownerRole = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);

        $customerRole->permissions()->sync(
            Permission::query()->whereIn('slug', ['place_order', 'view_own_orders', 'cancel_own_order'])->pluck('id')
        );

        $ownerRole->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'reject_restaurant_orders',
                'prepare_restaurant_orders',
                'complete_restaurant_orders',
            ])->pluck('id')
        );
    }
}
