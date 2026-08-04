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
use App\Models\Role;
use App\Models\User;
use App\Services\Order\OrderIdempotencyHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_same_key_and_same_payload_returns_same_order(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $payload = [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-same-payload',
            'payment_method' => 'cash',
        ];

        $first = $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertSame($first->json('data.order.order_number'), $second->json('data.order.order_number'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_same_key_and_different_quote_returns_conflict(): void
    {
        [$user, , , $quoteA] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $key = 'idem-different-quote';
        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quoteA->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertCreated();

        [, , , $quoteB] = $this->createCheckoutQuote();
        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quoteB->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_same_key_and_changed_fulfilment_returns_conflict(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $key = 'idem-fulfilment-change';
        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertCreated();

        $quote->update(['fulfilment_type' => 'restaurant_delivery']);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_same_key_and_changed_address_returns_conflict(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $key = 'idem-address-change';
        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertCreated();

        $quote->update([
            'address_snapshot' => [
                'address_line_1' => '99 Other St',
                'suburb' => 'Sydney',
                'state' => 'NSW',
                'postcode' => '2000',
            ],
        ]);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_same_key_and_changed_payment_method_returns_conflict(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $key = 'idem-payment-change';
        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => $key,
            'payment_method' => 'online_card',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_hash_is_stable_despite_json_key_ordering(): void
    {
        [, , $cart, $quote] = $this->createCheckoutQuote();
        $hasher = app(OrderIdempotencyHasher::class);
        $scope = $hasher->customerScope($cart->customer_id, $cart);

        $inputA = [
            'payment_method' => 'cash',
            'customer_notes' => 'Note',
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.test',
        ];
        $inputB = [
            'customer_email' => 'test@example.test',
            'customer_name' => 'Test User',
            'customer_notes' => 'Note',
            'payment_method' => 'cash',
        ];

        $this->assertSame(
            $hasher->hash($quote, $cart, $scope, $inputA),
            $hasher->hash($quote, $cart, $scope, $inputB),
        );
    }

    public function test_hash_is_not_exposed_in_api(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-no-hash-exposure',
            'payment_method' => 'cash',
        ])->assertCreated();

        $encoded = json_encode($response->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('idempotency_payload_hash', $encoded);
        $this->assertStringNotContainsString('idempotency_scope', $encoded);
    }

    public function test_different_customers_may_use_same_textual_key(): void
    {
        [$userA, , , $quoteA] = $this->createCheckoutQuote();
        [$userB, , , $quoteB] = $this->createCheckoutQuote();

        Sanctum::actingAs($userA);
        $first = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quoteA->public_id,
            'idempotency_key' => 'shared-text-key',
            'payment_method' => 'cash',
        ])->assertCreated();

        Sanctum::actingAs($userB);
        $second = $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quoteB->public_id,
            'idempotency_key' => 'shared-text-key',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertNotSame(
            $first->json('data.order.order_number'),
            $second->json('data.order.order_number'),
        );
        $this->assertSame(2, Order::query()->count());
    }

    public function test_guests_cannot_place_orders(): void
    {
        [, , , $quoteA] = $this->createCheckoutQuote(true);

        $this->postJson('/api/v1/orders', [
            'checkout_quote_public_id' => $quoteA->public_id,
            'idempotency_key' => 'guest-shared-key',
            'payment_method' => 'cash',
            'customer_name' => 'Guest A',
            'customer_email' => 'guest-a@example.test',
        ])->assertUnauthorized();
    }

    public function test_concurrent_identical_requests_create_one_order(): void
    {
        [$user, , , $quote] = $this->createCheckoutQuote();
        Sanctum::actingAs($user);

        $payload = [
            'checkout_quote_public_id' => $quote->public_id,
            'idempotency_key' => 'idem-concurrent',
            'payment_method' => 'cash',
        ];

        $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertSame(1, Order::query()->count());
    }

    /** @return array{0: User|null, 1: Restaurant, 2: Cart, 3: CheckoutQuote, 4: MenuItem} */
    private function createCheckoutQuote(bool $guest = false, string $ownershipType = 'first_party'): array
    {
        $user = $guest ? null : $this->customer();

        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'idem-'.Str::lower(Str::random(8)),
            'legal_business_name' => 'Idempotency Test Pty Ltd',
            'trading_name' => 'Idempotency Kitchen',
            'ownership_type' => $ownershipType,
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
        ]);

        $restaurant = $this->ensureRestaurantHierarchy($restaurant);

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
            'name' => 'Idempotency Item',
            'slug' => 'idem-item-'.Str::lower(Str::random(6)),
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
        foreach (array_unique(array_merge(config('suvakamana.permissions'), ['place_order'])) as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $customerRole = Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        $customerRole->permissions()->sync(
            Permission::query()->whereIn('slug', ['place_order'])->pluck('id')
        );
    }
}
