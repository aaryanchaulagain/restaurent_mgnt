<?php

namespace Tests\Feature\Payment;

use App\Domain\Payments\Contracts\ConnectedAccountProvider;
use App\Models\Restaurant;
use App\Models\RestaurantPaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\PaymentTestHelpers;

class ConnectedAccountTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPaymentPermissions();
    }

    public function test_restaurant_owner_can_create_and_show_account(): void
    {
        $restaurant = $this->createPaymentRestaurant('third_party');
        [$owner] = $this->paymentRestaurantOwner($restaurant);

        $this->mock(ConnectedAccountProvider::class, function ($mock) {
            $mock->shouldReceive('createAccount')->once()->andReturn($this->mockConnectedAccountResult('acct_created_test'));
            $mock->shouldReceive('refreshAccountStatus')->never();
        });

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/restaurant/payment-account')
            ->assertStatus(201)
            ->assertJsonPath('data.payment_account.onboarding_status', 'not_started');

        $this->getJson('/api/v1/restaurant/payment-account')
            ->assertOk()
            ->assertJsonPath('data.payment_account.ownership_type', 'third_party');
    }

    public function test_cross_restaurant_payment_summary_denied(): void
    {
        $restaurantA = $this->createPaymentRestaurant('first_party');
        $restaurantB = $this->createPaymentRestaurant('first_party');
        [$ownerA] = $this->paymentRestaurantOwner($restaurantA);

        $customer = $this->paymentCustomer();
        $orderB = $this->createOnlineOrder($customer, $restaurantB, 2000, 'awaiting_restaurant');
        $this->createPaidPayment($orderB);

        Sanctum::actingAs($ownerA);

        $this->getJson('/api/v1/restaurant/orders/'.$orderB->public_id.'/payment-summary')
            ->assertStatus(404);
    }

    public function test_first_party_show_does_not_require_connected_account(): void
    {
        $restaurant = $this->createPaymentRestaurant('first_party');
        [$owner] = $this->paymentRestaurantOwner($restaurant);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/restaurant/payment-account')
            ->assertOk()
            ->assertJsonPath('data.payment_account.requires_connected_account', false);

        $this->mock(ConnectedAccountProvider::class, function ($mock) {
            $mock->shouldReceive('createAccount')->once()->andReturn($this->mockConnectedAccountResult());
        });

        $this->postJson('/api/v1/restaurant/payment-account')
            ->assertStatus(201);
    }

    public function test_staff_without_manage_payment_accounts_gets_403(): void
    {
        $restaurant = $this->createPaymentRestaurant('third_party');
        RestaurantPaymentAccount::query()->create([
            'restaurant_id' => $restaurant->id,
            'provider' => 'stripe',
            'external_account_id' => 'acct_staff_block',
            'onboarding_status' => 'active',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'country' => 'AU',
            'default_currency' => 'AUD',
        ]);

        [$staff] = $this->paymentRestaurantOwner($restaurant, 'restaurant_staff');
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/restaurant/payment-account')->assertStatus(403);
        $this->postJson('/api/v1/restaurant/payment-account')->assertStatus(403);
    }
}
