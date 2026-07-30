<?php

namespace Tests\Feature\Payment;

use App\Domain\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\PaymentTestHelpers;

class PaymentIsolationTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPaymentPermissions();
    }

    public function test_customer_cannot_view_another_customers_payment(): void
    {
        $customerA = $this->paymentCustomer();
        $customerB = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');

        $orderA = $this->createOnlineOrder($customerA, $restaurant, 2000);
        $this->createPaidPayment($orderA);

        $orderB = $this->createOnlineOrder($customerB, $restaurant, 2100);
        $orderB->update(['status' => 'awaiting_restaurant', 'payment_status' => PaymentStatus::Paid->value]);
        $this->createPaidPayment($orderB);

        Sanctum::actingAs($customerA);

        $this->getJson('/api/v1/orders/'.$orderB->public_id.'/payment')->assertStatus(404);
    }

    public function test_restaurant_cannot_view_other_restaurant_payment_summary(): void
    {
        $restaurantA = $this->createPaymentRestaurant('first_party');
        $restaurantB = $this->createPaymentRestaurant('first_party');
        [$ownerA] = $this->paymentRestaurantOwner($restaurantA);

        $customer = $this->paymentCustomer();
        $orderB = $this->createOnlineOrder($customer, $restaurantB, 2300, 'awaiting_restaurant');
        $orderB->update(['payment_status' => PaymentStatus::Paid->value]);
        $this->createPaidPayment($orderB);

        Sanctum::actingAs($ownerA);

        $this->getJson('/api/v1/restaurant/orders/'.$orderB->public_id.'/payment-summary')
            ->assertStatus(404);
    }

    public function test_admin_can_list_payments_with_permission(): void
    {
        $admin = $this->paymentSuperAdmin();
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2500, 'awaiting_restaurant');
        $order->update(['payment_status' => PaymentStatus::Paid->value]);
        $payment = $this->createPaidPayment($order);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/admin/payments/'.$payment->public_id)
            ->assertOk()
            ->assertJsonPath('data.payment.public_id', $payment->public_id);
    }
}
