<?php

namespace Tests\Feature\Payment;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PaymentTestHelpers;

class ReconciliationTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPaymentPermissions();
    }

    public function test_reconcile_command_runs_for_stale_pending_payment(): void
    {
        Carbon::setTestNow(now());

        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2600);

        $payment = Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Pending->value,
            'currency' => 'AUD',
            'amount_cents' => 2600,
            'amount_received_cents' => 0,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => 0,
            'restaurant_share_cents' => 2600,
            'external_payment_intent_id' => 'pi_reconcile_test',
            'transfer_group' => 'order_'.$order->public_id,
            'updated_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
        ]);

        $this->mock(PaymentProvider::class, function ($mock) {
            $mock->shouldReceive('retrievePaymentIntent')
                ->once()
                ->with('pi_reconcile_test')
                ->andReturn($this->mockPaymentIntentResult('pi_reconcile_test', 'processing', 2600));
        });

        $this->artisan('payments:reconcile', ['--payment' => $payment->public_id])
            ->assertExitCode(0);

        $this->assertSame(PaymentStatus::Processing->value, $payment->fresh()->status);

        Carbon::setTestNow();
    }
}
