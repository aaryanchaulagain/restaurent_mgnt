<?php

namespace Tests\Feature\Inventory;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Business;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutQuote;
use App\Models\InventoryReservation;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemInventory;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\InventoryReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_place_order_reserves_counted_stock(): void
    {
        [$customer, $restaurant, $quote, $item, $inventory] = $this->groceryCheckout(stock: 10, qty: 3);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-reserve-1',
            'payment_method' => 'cash',
        ])->assertCreated();

        $order = Order::query()->where('order_number', $response->json('data.order.order_number'))->firstOrFail();

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => InventoryReservation::STATUS_ACTIVE,
            'quantity' => 3,
        ]);

        $inventory->refresh();
        $this->assertSame(10, $inventory->quantity_on_hand);

        $payload = app(\App\Services\Inventory\MenuItemInventoryService::class)->toPayload($inventory);
        $this->assertSame(3, $payload['quantity_reserved']);
        $this->assertSame(7, $payload['quantity_available']);
    }

    public function test_insufficient_stock_blocks_order(): void
    {
        [$customer, , $quote] = $this->groceryCheckout(stock: 2, qty: 5);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-oversell-1',
            'payment_method' => 'cash',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertSame(0, InventoryReservation::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accept_consumes_reservation(): void
    {
        [$customer, $restaurant, $quote, $item, $inventory] = $this->groceryCheckout(stock: 8, qty: 2);
        Sanctum::actingAs($customer);
        $order = $this->place($quote, 'idem-consume-1');

        [$owner] = $this->restaurantOwner($restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept", [
            'estimated_ready_minutes' => 20,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => InventoryReservation::STATUS_CONSUMED,
        ]);
        $this->assertSame(6, $inventory->fresh()->quantity_on_hand);

        // Idempotent consume
        app(InventoryReservationService::class)->consumeForOrder($order->fresh());
        $this->assertSame(6, $inventory->fresh()->quantity_on_hand);
    }

    public function test_reject_releases_reservation_without_stock_change(): void
    {
        [$customer, $restaurant, $quote, , $inventory] = $this->groceryCheckout(stock: 5, qty: 2);
        Sanctum::actingAs($customer);
        $order = $this->place($quote, 'idem-reject-1');

        [$owner] = $this->restaurantOwner($restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/reject", [
            'reason' => 'item_unavailable',
            'explanation' => 'Cannot fulfil today',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => InventoryReservation::STATUS_RELEASED,
        ]);
        $this->assertSame(5, $inventory->fresh()->quantity_on_hand);
    }

    public function test_expire_releases_reservation(): void
    {
        [$customer, , $quote, , $inventory] = $this->groceryCheckout(stock: 4, qty: 1);
        Sanctum::actingAs($customer);
        $order = $this->place($quote, 'idem-expire-1');
        $order->update(['expires_at' => now()->subMinute()]);

        Artisan::call('orders:expire-unaccepted');

        $this->assertSame('expired', $order->fresh()->status);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => InventoryReservation::STATUS_RELEASED,
        ]);
        $this->assertSame(4, $inventory->fresh()->quantity_on_hand);
    }

    public function test_boolean_restaurant_skips_reservation(): void
    {
        [$customer, , $quote] = $this->restaurantCheckout();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-boolean-1',
            'payment_method' => 'cash',
        ])->assertCreated();

        $order = Order::query()->where('order_number', $response->json('data.order.order_number'))->firstOrFail();
        $this->assertSame(0, InventoryReservation::query()->where('order_id', $order->id)->count());
    }

    public function test_release_expired_reservations_command(): void
    {
        [$customer, , $quote] = $this->groceryCheckout(stock: 3, qty: 1);
        Sanctum::actingAs($customer);
        $order = $this->place($quote, 'idem-cmd-expire-1');

        InventoryReservation::query()->where('order_id', $order->id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        Artisan::call('inventory:release-expired-reservations');

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => InventoryReservation::STATUS_RELEASED,
        ]);
    }

    public function test_concurrent_orders_cannot_oversell(): void
    {
        [$customerA, $restaurant, $quoteA, $item, $inventory] = $this->groceryCheckout(stock: 3, qty: 2);
        [, , $quoteB] = $this->groceryCheckoutForRestaurant($restaurant, $item, $inventory, qty: 2);

        Sanctum::actingAs($customerA);
        $this->place($quoteA, 'idem-race-a');

        $customerB = $this->customer();
        $quoteB->update(['customer_id' => $customerB->id]);
        Sanctum::actingAs($customerB);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quoteB->public_id,
            'idempotency_key' => 'idem-race-b',
            'payment_method' => 'cash',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    }

    private function place(CheckoutQuote $quote, string $key): Order
    {
        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertCreated();

        return Order::query()
            ->where('order_number', $response->json('data.order.order_number'))
            ->firstOrFail();
    }

    /**
     * @return array{0: User, 1: Restaurant, 2: CheckoutQuote, 3: MenuItem, 4: MenuItemInventory}
     */
    private function groceryCheckout(int $stock, int $qty): array
    {
        $customer = $this->customer();
        $business = Business::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Grocery Biz',
            'slug' => 'grocery-'.Str::random(6),
            'business_type' => 'grocery',
            'status' => 'active',
        ]);
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'grocery-'.Str::lower(Str::random(6)),
            'legal_business_name' => 'Grocery Test Pty Ltd',
            'trading_name' => 'Grocery Test',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'business_id' => $business->id,
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
            'name' => 'Pantry',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $category->id,
            'name' => 'Rice',
            'slug' => 'rice-'.Str::random(4),
            'base_price_cents' => 500,
            'is_active' => true,
            'is_available' => true,
        ]);
        $inventory = MenuItemInventory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $item->id,
            'menu_item_variant_id' => null,
            'variant_scope' => 0,
            'track_stock' => true,
            'quantity_on_hand' => $stock,
            'low_stock_threshold' => 2,
            'force_unavailable' => false,
        ]);

        $cart = Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'active',
            'currency' => 'AUD',
        ]);
        CartItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'menu_item_id' => $item->id,
            'quantity' => $qty,
            'unit_price_snapshot_cents' => 500,
            'estimated_total_cents' => 500 * $qty,
        ]);
        $quote = CheckoutQuote::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'fulfilment_type' => 'pickup',
            'pricing_snapshot' => [
                'subtotal_cents' => 500 * $qty,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'service_fee_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_before_delivery_cents' => 500 * $qty,
            ],
            'warnings' => [],
            'expires_at' => now()->addMinutes(15),
            'status' => 'active',
        ]);

        return [$customer, $restaurant, $quote, $item, $inventory];
    }

    /**
     * @return array{0: User, 1: Restaurant, 2: CheckoutQuote}
     */
    private function groceryCheckoutForRestaurant(
        Restaurant $restaurant,
        MenuItem $item,
        MenuItemInventory $inventory,
        int $qty,
    ): array {
        $customer = $this->customer();
        $cart = Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'active',
            'currency' => 'AUD',
        ]);
        CartItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'menu_item_id' => $item->id,
            'quantity' => $qty,
            'unit_price_snapshot_cents' => 500,
            'estimated_total_cents' => 500 * $qty,
        ]);
        $quote = CheckoutQuote::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'fulfilment_type' => 'pickup',
            'pricing_snapshot' => [
                'subtotal_cents' => 500 * $qty,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'service_fee_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_before_delivery_cents' => 500 * $qty,
            ],
            'warnings' => [],
            'expires_at' => now()->addMinutes(15),
            'status' => 'active',
        ]);

        return [$customer, $restaurant, $quote];
    }

    /**
     * @return array{0: User, 1: Restaurant, 2: CheckoutQuote}
     */
    private function restaurantCheckout(): array
    {
        $customer = $this->customer();
        $business = Business::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Restaurant Biz',
            'slug' => 'rest-'.Str::random(6),
            'business_type' => 'restaurant',
            'status' => 'active',
        ]);
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'rest-'.Str::lower(Str::random(6)),
            'legal_business_name' => 'Rest Test Pty Ltd',
            'trading_name' => 'Rest Test',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'business_id' => $business->id,
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
            'name' => 'Curry',
            'slug' => 'curry-'.Str::random(4),
            'base_price_cents' => 1200,
            'is_active' => true,
            'is_available' => true,
        ]);
        $cart = Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'active',
            'currency' => 'AUD',
        ]);
        CartItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'menu_item_id' => $item->id,
            'quantity' => 1,
            'unit_price_snapshot_cents' => 1200,
            'estimated_total_cents' => 1200,
        ]);
        $quote = CheckoutQuote::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'fulfilment_type' => 'pickup',
            'pricing_snapshot' => [
                'subtotal_cents' => 1200,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'service_fee_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_before_delivery_cents' => 1200,
            ],
            'warnings' => [],
            'expires_at' => now()->addMinutes(15),
            'status' => 'active',
        ]);

        return [$customer, $restaurant, $quote];
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

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $customer = Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        $customer->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_cart', 'manage_cart', 'prepare_checkout', 'place_order', 'view_own_orders', 'cancel_own_order',
            ])->pluck('id')
        );

        $owner = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);
        $owner->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_restaurant_orders', 'accept_restaurant_orders', 'reject_restaurant_orders',
                'view_inventory', 'manage_inventory',
            ])->pluck('id')
        );
    }
}
