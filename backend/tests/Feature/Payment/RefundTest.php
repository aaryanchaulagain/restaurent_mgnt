<?php

namespace Tests\Feature\Payment;

use App\Domain\Payments\Contracts\RefundProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\PaymentTestHelpers;

class RefundTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPaymentPermissions();
    }

    public function test_admin_with_mfa_can_create_full_refund(): void
    {
        $admin = $this->paymentSuperAdmin();
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 3000, 'awaiting_restaurant');
        $payment = $this->createPaidPayment($order);

        $this->mock(RefundProvider::class, function ($mock) {
            $mock->shouldReceive('createRefund')
                ->once()
                ->andReturn($this->mockRefundResult('re_admin_full', 3000));
        });

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->postJson('/api/v1/admin/payments/'.$payment->public_id.'/refunds', [
            'amount_cents' => 3000,
            'reason_category' => 'customer_request',
            'confirm' => true,
            'idempotency_key' => 'admin-refund-full-1',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.refund.amount_cents', 3000);

        $this->assertDatabaseHas('refunds', [
            'payment_id' => $payment->id,
            'amount_cents' => 3000,
            'status' => RefundStatus::Processing->value,
        ]);
    }

    public function test_refund_amount_too_high_rejected(): void
    {
        $admin = $this->paymentSuperAdmin();
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2000, 'awaiting_restaurant');
        $payment = $this->createPaidPayment($order);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->postJson('/api/v1/admin/payments/'.$payment->public_id.'/refunds', [
            'amount_cents' => 5000,
            'reason_category' => 'customer_request',
            'confirm' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REFUND_EXCEEDS_AVAILABLE_AMOUNT');
    }

    public function test_zero_refund_rejected(): void
    {
        $admin = $this->paymentSuperAdmin();
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2000, 'awaiting_restaurant');
        $payment = $this->createPaidPayment($order);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->postJson('/api/v1/admin/payments/'.$payment->public_id.'/refunds', [
            'amount_cents' => 0,
            'reason_category' => 'customer_request',
            'confirm' => true,
        ])->assertStatus(422);
    }

    public function test_customer_cannot_create_admin_refund(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2000, 'awaiting_restaurant');
        $payment = $this->createPaidPayment($order);

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/admin/payments/'.$payment->public_id.'/refunds', [
            'amount_cents' => 500,
            'reason_category' => 'customer_request',
            'confirm' => true,
        ])->assertStatus(403);
    }

    public function test_restaurant_refund_request_creates_local_requested_refund(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        [$owner] = $this->paymentRestaurantOwner($restaurant);
        $order = $this->createOnlineOrder($customer, $restaurant, 2400, 'awaiting_restaurant');
        $order->update(['payment_status' => PaymentStatus::Paid->value]);
        $payment = $this->createPaidPayment($order);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/restaurant/orders/'.$order->public_id.'/refund-requests', [
            'amount_cents' => 800,
            'reason_category' => 'order_issue',
            'confirm' => true,
            'idempotency_key' => 'restaurant-local-refund-1',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.refund.status', RefundStatus::Requested->value);

        $refund = Refund::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertNull($refund->external_refund_id);
        $this->assertSame(RefundStatus::Requested->value, $refund->status);
    }
}
