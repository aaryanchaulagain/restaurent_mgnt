<?php

namespace Tests\Feature\Order;

use App\Enums\Partner\RestaurantStatus;
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
use App\Services\Order\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use App\Exceptions\OrderApiException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_customer_places_order_from_valid_quote(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote(false, 'first_party');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-customer-1',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertStringStartsWith('SVK-', $response->json('data.order.order_number'));
        $response->assertJsonPath('data.order.status', 'awaiting_restaurant');
    }

    public function test_guest_places_order(): void
    {
        [, , , $quote] = $this->createCheckoutQuote(true, 'first_party');

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-guest-1',
            'payment_method' => 'cash',
            'customer_name' => 'Guest User',
            'customer_email' => 'guest@example.test',
        ])->assertCreated()
            ->assertJsonPath('data.order.status', 'awaiting_restaurant')
            ->assertJsonStructure(['data' => ['order' => ['guest_access_token']]]);
    }

    public function test_expired_quote_rejected(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        $quote->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-expired-1',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_converted_quote_rejected(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        $quote->update(['status' => 'converted']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-converted-1',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_empty_cart_rejected(): void
    {
        [$user, , $cart, $quote] = $this->createCheckoutQuote();
        CartItem::query()->where('cart_id', $cart->id)->delete();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-empty-cart-1',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_unavailable_item_rejected(): void
    {
        [$user, , , $quote, $item] = $this->createCheckoutQuote();
        $item->update(['is_available' => false]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-unavailable-1',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_same_idempotency_key_returns_same_order(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $payload = [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-same-key-1',
            'payment_method' => 'cash',
        ];

        $first = $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertSame(
            $first->json('data.order.order_number'),
            $second->json('data.order.order_number')
        );
    }

    public function test_no_duplicate_order_created(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $payload = [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-no-dup-1',
            'payment_method' => 'cash',
        ];

        $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertSame(1, Order::query()->count());
    }

    public function test_accept_awaiting_order(): void
    {
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'accepted');
    }

    public function test_cannot_accept_pending_payment_order(): void
    {
        $this->mock(\App\Domain\Payments\Contracts\PaymentProvider::class, function ($mock) {
            $mock->shouldReceive('createPaymentIntent')
                ->andReturn(new \App\Domain\Payments\DTOs\PaymentIntentResult(
                    externalId: 'pi_pending_accept_test',
                    clientSecret: 'pi_pending_accept_test_secret',
                    status: 'requires_payment_method',
                    amountCents: 2500,
                    currency: 'AUD',
                    chargeId: null,
                    rawStatus: 'requires_payment_method',
                ));
        });

        [$user, $restaurant, , $quote] = $this->createCheckoutQuote(false, 'first_party');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-pending-pay-accept',
            'payment_method' => 'online_card',
        ])->assertCreated();

        $order = Order::query()
            ->where('order_number', $response->json('data.order.order_number'))
            ->firstOrFail();

        $this->assertSame('pending_payment', $order->status);

        [$owner] = $this->restaurantOwner($restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION');
    }

    public function test_reject_awaiting_order(): void
    {
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/reject", [
            'reason' => 'item_unavailable',
            'explanation' => 'Out of stock',
        ])->assertOk()
            ->assertJsonPath('data.order.status', 'rejected');
    }

    public function test_accepted_to_preparing(): void
    {
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'preparing');
    }

    public function test_preparing_to_ready(): void
    {
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/mark-ready")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'ready_for_pickup');
    }

    public function test_ready_to_completed(): void
    {
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/mark-ready")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/complete-pickup")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'completed_pickup');
    }

    public function test_invalid_transition_rejected(): void
    {
        $order = $this->placeCustomerOrder();
        [$owner] = $this->restaurantOwner($order->restaurant);
        $service = app(OrderTransitionService::class);

        $service->transition($order, 'accepted', $owner, 'restaurant_user');
        $service->transition($order->fresh(), 'preparing', $owner, 'restaurant_user');

        $this->expectException(OrderApiException::class);
        $service->transition($order->fresh(), 'awaiting_restaurant', $owner, 'restaurant_user');
    }

    public function test_customer_cancels_awaiting_order(): void
    {
        [$user, $order] = $this->placeCustomerOrderWithUser();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/orders/{$order->public_id}/cancel", [
            'reason' => 'Changed my mind',
        ])->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled');
    }

    public function test_customer_cannot_cancel_preparing_order(): void
    {
        [$user, $order] = $this->placeCustomerOrderWithUser();
        [$owner] = $this->restaurantOwner($order->restaurant);
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/accept")->assertOk();
        $this->postJson("/api/v1/restaurant/orders/{$order->public_id}/start-preparing")->assertOk();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/orders/{$order->public_id}/cancel")->assertStatus(422);
    }

    public function test_restaurant_a_cannot_see_restaurant_b_order(): void
    {
        [, $orderA] = $this->placeCustomerOrderWithUser('third_party');
        [, $restaurantB] = $this->createCheckoutQuote(false, 'first_party');
        $orderB = $this->createRestaurantOrder($restaurantB);

        [$ownerA] = $this->restaurantOwner($orderA->restaurant);
        Sanctum::actingAs($ownerA);

        $this->getJson("/api/v1/restaurant/orders/{$orderB->public_id}")
            ->assertStatus(404);
    }

    public function test_item_snapshot_preserved(): void
    {
        [, $order] = $this->placeCustomerOrderWithUser();
        $originalName = $order->items()->firstOrFail()->item_name_snapshot;

        $item = MenuItem::query()->findOrFail($order->items()->firstOrFail()->menu_item_id);
        $item->update(['name' => 'Renamed Item']);

        $order->refresh();
        $this->assertSame($originalName, $order->items()->firstOrFail()->item_name_snapshot);
    }

    public function test_first_party_commission_zero(): void
    {
        [, $order] = $this->placeCustomerOrderWithUser('first_party');
        $this->assertSame(0.0, $order->commission_rate_snapshot);
    }

    public function test_expire_command(): void
    {
        [, $order] = $this->placeCustomerOrderWithUser();
        $order->update(['expires_at' => now()->subMinute()]);

        Artisan::call('orders:expire-unaccepted');

        $this->assertSame('expired', $order->fresh()->status);
    }

    public function test_guest_tracking_with_token(): void
    {
        [, $order, $token] = $this->placeGuestOrder();

        $this->getJson("/api/v1/guest/orders/{$order->order_number}?token={$token}")
            ->assertOk()
            ->assertJsonPath('data.order.order_number', $order->order_number);
    }

    public function test_guest_tracking_without_token(): void
    {
        [, $order] = $this->placeGuestOrder();

        $this->getJson("/api/v1/guest/orders/{$order->order_number}")
            ->assertStatus(422);
    }

    public function test_guest_tracking_wrong_token(): void
    {
        [, $order] = $this->placeGuestOrder();

        $this->getJson("/api/v1/guest/orders/{$order->order_number}?token=invalid-token")
            ->assertStatus(404);
    }

    /** @return array{0: User|null, 1: Restaurant, 2: Cart, 3: CheckoutQuote, 4: MenuItem} */
    private function createCheckoutQuote(bool $guest = false, string $ownershipType = 'first_party'): array
    {
        $user = $guest ? null : $this->customer();

        $slugPrefix = $ownershipType === 'first_party' ? 'suvakamana-restaurant' : 'partner-restaurant';
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(6)),
            'legal_business_name' => 'Order Test Pty Ltd',
            'trading_name' => 'Order Test Kitchen',
            'ownership_type' => $ownershipType,
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
            'name' => 'Order Test Item',
            'slug' => 'order-test-item-'.Str::lower(Str::random(6)),
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

    private function placeCustomerOrder(string $ownershipType = 'first_party'): Order
    {
        [, $order] = $this->placeCustomerOrderWithUser($ownershipType);
        return $order;
    }

    private function createRestaurantOrder(Restaurant $restaurant): Order
    {
        return Order::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_number' => 'SVK-TEST-'.Str::upper(Str::random(8)),
            'idempotency_key' => 'manual-'.Str::lower(Str::random(8)),
            'restaurant_id' => $restaurant->id,
            'status' => 'awaiting_restaurant',
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'subtotal_cents' => 0,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'service_fee_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => 0,
            'commission_rate_snapshot' => 0,
            'commission_amount_cents' => 0,
            'restaurant_net_estimate_cents' => 0,
            'placed_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Order} */
    private function placeCustomerOrderWithUser(string $ownershipType = 'first_party'): array
    {
        [$user, , , $quote] = $this->createCheckoutQuote(false, $ownershipType);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-'.Str::lower(Str::random(12)),
            'payment_method' => 'cash',
        ])->assertCreated();

        $order = Order::query()
            ->where('order_number', $response->json('data.order.order_number'))
            ->with('items')
            ->firstOrFail();

        return [$user, $order];
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: Order, 2: string} */
    private function placeGuestOrder(): array
    {
        [, , , $quote] = $this->createCheckoutQuote(true, 'first_party');
        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-guest-'.Str::lower(Str::random(8)),
            'payment_method' => 'cash',
            'customer_name' => 'Guest User',
            'customer_email' => 'guest@example.test',
        ])->assertCreated();

        $order = Order::query()
            ->where('order_number', $response->json('data.order.order_number'))
            ->with('items')
            ->firstOrFail();

        return [$response, $order, (string) $response->json('data.order.guest_access_token')];
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
                'cancel_restaurant_orders',
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
                'cancel_restaurant_orders',
            ])->pluck('id')
        );
    }
}
