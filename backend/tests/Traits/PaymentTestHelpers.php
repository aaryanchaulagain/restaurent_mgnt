<?php

namespace Tests\Traits;

use App\Domain\Payments\DTOs\ConnectedAccountResult;
use App\Domain\Payments\DTOs\OnboardingLinkResult;
use App\Domain\Payments\DTOs\PaymentIntentResult;
use App\Domain\Payments\DTOs\RefundResult;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Enums\Partner\RestaurantStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutQuote;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MfaMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantPaymentAccount;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

trait PaymentTestHelpers
{
    protected function seedPaymentPermissions(): void
    {
        $slugs = array_unique(array_merge(
            config('suvakamana.permissions'),
            [
                'place_order',
                'view_own_orders',
                'view_own_order_payment',
                'retry_own_payment',
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'view_restaurant_payment_summaries',
                'request_restaurant_refund',
                'manage_payment_accounts',
                'view_all_platform_payments',
                'view_platform_payment_details',
                'create_full_refund',
                'create_partial_refund',
            ],
        ));

        foreach ($slugs as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $customerRole = Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        $ownerRole = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);
        $staffRole = Role::query()->firstOrCreate(['slug' => 'restaurant_staff'], ['name' => 'Staff', 'guard' => 'web']);
        $adminRole = Role::query()->firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'guard' => 'web']);

        $customerRole->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'place_order',
                'view_own_orders',
                'view_own_order_payment',
                'retry_own_payment',
            ])->pluck('id')
        );

        $ownerRole->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'view_restaurant_payment_summaries',
                'request_restaurant_refund',
                'manage_payment_accounts',
            ])->pluck('id')
        );

        $staffRole->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_restaurant_orders',
                'view_restaurant_payment_summaries',
            ])->pluck('id')
        );

        $adminRole->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_all_platform_payments',
                'view_platform_payment_details',
                'create_full_refund',
                'create_partial_refund',
            ])->pluck('id')
        );
    }

    protected function paymentCustomer(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->load('roles.permissions');

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function paymentSuperAdmin(bool $withMfa = true, array $permissions = null): User
    {
        $permissions ??= [
            'view_all_platform_payments',
            'view_platform_payment_details',
            'create_full_refund',
            'create_partial_refund',
        ];

        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));
        $user->roles()->attach($role->id);
        $user->load('roles.permissions');

        if ($withMfa) {
            MfaMethod::query()->create([
                'user_id' => $user->id,
                'type' => 'totp',
                'secret_encrypted' => 'test-secret',
                'is_confirmed' => true,
                'is_primary' => true,
                'confirmed_at' => now(),
            ]);
        }

        return $user;
    }

    /** @return array{0: User, 1: Restaurant} */
    protected function paymentRestaurantOwner(Restaurant $restaurant, string $roleSlug = 'restaurant_owner'): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

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

    protected function createPaymentRestaurant(string $ownershipType = 'first_party', bool $withActiveAccount = false): Restaurant
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'pay-test-'.Str::lower(Str::random(8)),
            'legal_business_name' => 'Payment Test Pty Ltd',
            'trading_name' => 'Payment Test Kitchen',
            'ownership_type' => $ownershipType,
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
        ]);

        if ($ownershipType === 'third_party' && $withActiveAccount) {
            RestaurantPaymentAccount::query()->create([
                'restaurant_id' => $restaurant->id,
                'provider' => 'stripe',
                'external_account_id' => 'acct_test_'.Str::lower(Str::random(10)),
                'onboarding_status' => 'active',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => true,
                'online_payments_enabled' => true,
                'requirements_currently_due' => [],
                'requirements_eventually_due' => [],
                'country' => 'AU',
                'default_currency' => 'AUD',
                'last_synced_at' => now(),
                'onboarding_completed_at' => now(),
            ]);
        }

        return $restaurant;
    }

    protected function createOnlineOrder(
        User $customer,
        Restaurant $restaurant,
        int $totalCents = 2500,
        string $status = 'pending_payment',
        float $commissionRate = 0.0,
    ): Order {
        $commissionAmount = $commissionRate > 0 ? (int) round($totalCents * $commissionRate) : 0;

        $order = Order::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_number' => 'SVK-PAY-'.Str::upper(Str::random(8)),
            'idempotency_key' => 'pay-order-'.Str::lower(Str::random(12)),
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'payment_method' => 'online_card',
            'payment_status' => PaymentStatus::Unpaid->value,
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'subtotal_cents' => $totalCents,
            'discount_cents' => 0,
            'total_cents' => $totalCents,
            'commission_rate_snapshot' => $commissionRate,
            'commission_amount_cents' => $commissionAmount,
            'restaurant_net_estimate_cents' => max(0, $totalCents - $commissionAmount),
            'placed_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        OrderItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => null,
            'item_name_snapshot' => 'Test Item',
            'unit_price_cents' => $totalCents,
            'quantity' => 1,
            'line_subtotal_cents' => $totalCents,
            'line_total_cents' => $totalCents,
        ]);

        return $order;
    }

    protected function createPaidPayment(Order $order, array $overrides = []): Payment
    {
        $restaurant = $order->restaurant ?? Restaurant::query()->findOrFail($order->restaurant_id);
        $ownership = $restaurant->ownership_type === 'first_party' || $restaurant->isFirstParty()
            ? 'first_party'
            : 'third_party';
        $platformFee = (int) $order->commission_amount_cents;
        $account = $restaurant->paymentAccount;

        return Payment::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $order->restaurant_id,
            'customer_id' => $order->customer_id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Paid->value,
            'currency' => 'AUD',
            'amount_cents' => (int) $order->total_cents,
            'amount_received_cents' => (int) $order->total_cents,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => $platformFee,
            'restaurant_share_cents' => (int) $order->total_cents - $platformFee,
            'external_payment_intent_id' => 'pi_test_'.Str::lower(Str::random(12)),
            'external_charge_id' => 'ch_test_'.Str::lower(Str::random(12)),
            'connected_account_id' => $account?->external_account_id,
            'transfer_group' => 'order_'.$order->public_id,
            'paid_at' => now(),
            'metadata' => ['ownership_type' => $ownership],
        ], $overrides));
    }

    protected function mockPaymentIntentResult(
        string $externalId = 'pi_test_mock',
        string $rawStatus = 'requires_payment_method',
        int $amountCents = 2500,
    ): PaymentIntentResult {
        return new PaymentIntentResult(
            externalId: $externalId,
            clientSecret: 'pi_test_mock_secret',
            status: $rawStatus,
            amountCents: $amountCents,
            currency: 'AUD',
            chargeId: null,
            rawStatus: $rawStatus,
        );
    }

    protected function mockConnectedAccountResult(string $externalId = 'acct_test_mock'): ConnectedAccountResult
    {
        return new ConnectedAccountResult(
            externalAccountId: $externalId,
            onboardingStatus: 'pending',
            chargesEnabled: false,
            payoutsEnabled: false,
            detailsSubmitted: false,
            requirementsCurrentlyDue: [],
            requirementsEventuallyDue: [],
            disabledReason: null,
        );
    }

    protected function mockOnboardingLink(): OnboardingLinkResult
    {
        return new OnboardingLinkResult(
            url: 'https://connect.stripe.com/setup/test',
            expiresAt: now()->addHour(),
        );
    }

    protected function mockRefundResult(string $externalId = 're_test_mock', int $amountCents = 2500): RefundResult
    {
        return new RefundResult(
            externalRefundId: $externalId,
            status: 'pending',
            amountCents: $amountCents,
        );
    }

    /** @return array{0: User|null, 1: Restaurant, 2: Cart, 3: CheckoutQuote} */
    protected function createCheckoutQuoteForPayment(string $ownershipType = 'first_party'): array
    {
        $user = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant($ownershipType, $ownershipType === 'third_party');

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
            'name' => 'Payment Test Item',
            'slug' => 'pay-item-'.Str::lower(Str::random(6)),
            'base_price_cents' => 1250,
            'is_active' => true,
            'is_available' => true,
        ]);

        $cart = Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
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
            'customer_id' => $user->id,
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

        return [$user, $restaurant, $cart, $quote];
    }
}
