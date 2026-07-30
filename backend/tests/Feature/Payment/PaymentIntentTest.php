<?php

namespace Tests\Feature\Payment;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\PaymentFundsFlowService;
use App\Domain\Payments\Services\PaymentIntentService;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\RestaurantPaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\PaymentTestHelpers;

class PaymentIntentTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPaymentPermissions();
        config(['payments.max_retry_attempts' => 3]);
    }

    public function test_first_party_funds_flow_uses_platform_strategy(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 3200);

        $flow = app(PaymentFundsFlowService::class)->resolveForOrder($order);

        $this->assertSame('platform', $flow['strategy']);
        $this->assertSame(0, $flow['platform_fee_cents']);
        $this->assertSame(3200, $flow['restaurant_share_cents']);
        $this->assertNull($flow['connected_account_id']);
        $this->assertSame('first_party', $flow['ownership_type']);
    }

    public function test_third_party_funds_flow_uses_commission_snapshot(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('third_party', withActiveAccount: true);
        $order = $this->createOnlineOrder($customer, $restaurant, 4000, commissionRate: 0.125);

        $flow = app(PaymentFundsFlowService::class)->resolveForOrder($order->load('restaurant.paymentAccount'));

        $this->assertSame(500, $flow['platform_fee_cents']);
        $this->assertSame(3500, $flow['restaurant_share_cents']);
        $this->assertNotNull($flow['connected_account_id']);
        $this->assertSame('third_party', $flow['ownership_type']);
    }

    public function test_create_for_order_uses_order_total_cents(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2750);

        $this->mock(PaymentProvider::class, function ($mock) use ($order) {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->withArgs(function ($passedOrder, $attempt) use ($order) {
                    return (int) $passedOrder->total_cents === 2750
                        && (int) $attempt->amount_cents === 2750;
                })
                ->andReturn($this->mockPaymentIntentResult('pi_amount_test', 'requires_payment_method', 2750));
        });

        $result = app(PaymentIntentService::class)->createForOrder($order);

        $this->assertSame(2750, $result['payment']->amount_cents);
    }

    public function test_restricted_connected_account_throws(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('third_party');
        RestaurantPaymentAccount::query()->create([
            'restaurant_id' => $restaurant->id,
            'provider' => 'stripe',
            'external_account_id' => 'acct_restricted',
            'onboarding_status' => 'restricted',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'disabled_reason' => 'requirements.past_due',
            'requirements_currently_due' => ['individual.verification.document'],
            'requirements_eventually_due' => [],
            'country' => 'AU',
            'default_currency' => 'AUD',
        ]);

        $order = $this->createOnlineOrder($customer, $restaurant, 3000, commissionRate: 0.1);

        $this->expectException(PaymentException::class);

        try {
            app(PaymentIntentService::class)->createForOrder($order->load('restaurant.paymentAccount'));
        } catch (PaymentException $e) {
            $this->assertSame('CONNECTED_ACCOUNT_RESTRICTED', $e->errorCode);
            throw $e;
        }
    }

    public function test_already_paid_order_throws(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2000);
        $order->update(['payment_status' => PaymentStatus::Paid->value]);
        $this->createPaidPayment($order);

        $this->expectException(PaymentException::class);

        try {
            app(PaymentIntentService::class)->createForOrder($order->fresh());
        } catch (PaymentException $e) {
            $this->assertSame('PAYMENT_ALREADY_PAID', $e->errorCode);
            throw $e;
        }
    }

    public function test_attempt_limit_throws(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 1800);

        $payment = Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Pending->value,
            'currency' => 'AUD',
            'amount_cents' => 1800,
            'amount_received_cents' => 0,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => 0,
            'restaurant_share_cents' => 1800,
            'transfer_group' => 'order_'.$order->public_id,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            PaymentAttempt::query()->create([
                'public_id' => (string) \Illuminate\Support\Str::uuid(),
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'attempt_number' => $i,
                'idempotency_key' => 'limit-test:'.$i,
                'request_payload_hash' => hash('sha256', (string) $i),
                'status' => PaymentAttemptStatus::Failed->value,
                'amount_cents' => 1800,
                'currency' => 'AUD',
                'started_at' => now(),
            ]);
        }

        $this->expectException(PaymentException::class);

        try {
            app(PaymentIntentService::class)->createForOrder($order->fresh());
        } catch (PaymentException $e) {
            $this->assertSame('PAYMENT_ATTEMPT_LIMIT_REACHED', $e->errorCode);
            throw $e;
        }
    }
}
