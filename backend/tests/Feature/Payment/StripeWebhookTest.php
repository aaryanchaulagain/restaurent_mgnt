<?php

namespace Tests\Feature\Payment;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Enums\WebhookProcessingStatus;
use App\Domain\Payments\Services\StripeEventProcessor;
use App\Events\Order\OrderPlaced;
use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Stripe\Event as StripeEvent;
use Tests\TestCase;
use Tests\Traits\PaymentTestHelpers;

class StripeWebhookTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPaymentPermissions();
    }

    public function test_invalid_signature_returns_400_when_secret_configured(): void
    {
        config(['payments.stripe.webhook_secret' => 'whsec_test_secret_for_feature_test']);

        $this->postJson('/api/v1/webhooks/stripe', [], [
            'Stripe-Signature' => 'invalid',
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    public function test_succeeded_moves_pending_payment_order_to_awaiting_restaurant(): void
    {
        Event::fake([OrderPlaced::class]);

        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2400);
        $payment = Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Processing->value,
            'currency' => 'AUD',
            'amount_cents' => 2400,
            'amount_received_cents' => 0,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => 0,
            'restaurant_share_cents' => 2400,
            'external_payment_intent_id' => 'pi_webhook_success',
            'transfer_group' => 'order_'.$order->public_id,
        ]);

        $webhook = $this->makeWebhookEvent('evt_success_1', 'payment_intent.succeeded');
        $event = $this->stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_webhook_success',
            'amount' => 2400,
            'amount_received' => 2400,
            'currency' => 'aud',
            'latest_charge' => 'ch_webhook_success',
        ]);

        app(StripeEventProcessor::class)->process($event, $webhook);

        $order->refresh();
        $payment->refresh();

        $this->assertSame('awaiting_restaurant', $order->status);
        $this->assertSame(PaymentStatus::Paid->value, $payment->status);
        $this->assertSame(PaymentStatus::Paid->value, $order->payment_status);

        Event::assertDispatched(OrderPlaced::class);
    }

    public function test_duplicate_success_is_idempotent(): void
    {
        Event::fake([OrderPlaced::class]);

        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2100);
        $payment = Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Pending->value,
            'currency' => 'AUD',
            'amount_cents' => 2100,
            'amount_received_cents' => 0,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => 0,
            'restaurant_share_cents' => 2100,
            'external_payment_intent_id' => 'pi_webhook_dup',
            'transfer_group' => 'order_'.$order->public_id,
        ]);

        $payload = [
            'id' => 'pi_webhook_dup',
            'amount' => 2100,
            'amount_received' => 2100,
            'currency' => 'aud',
        ];
        $processor = app(StripeEventProcessor::class);
        $processor->process($this->stripeEvent('payment_intent.succeeded', $payload), $this->makeWebhookEvent('evt_dup_1'));
        $processor->process($this->stripeEvent('payment_intent.succeeded', $payload), $this->makeWebhookEvent('evt_dup_2'));

        Event::assertDispatched(OrderPlaced::class, 1);
        $this->assertSame('awaiting_restaurant', $order->fresh()->status);
        $this->assertSame(PaymentStatus::Paid->value, $payment->fresh()->status);
    }

    public function test_amount_mismatch_does_not_mark_paid(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2200);
        $payment = Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Pending->value,
            'currency' => 'AUD',
            'amount_cents' => 2200,
            'amount_received_cents' => 0,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => 0,
            'restaurant_share_cents' => 2200,
            'external_payment_intent_id' => 'pi_mismatch',
            'transfer_group' => 'order_'.$order->public_id,
        ]);

        $this->expectException(\App\Domain\Payments\Exceptions\PaymentException::class);

        app(StripeEventProcessor::class)->process(
            $this->stripeEvent('payment_intent.succeeded', [
                'id' => 'pi_mismatch',
                'amount' => 999,
                'amount_received' => 999,
                'currency' => 'aud',
            ]),
            $this->makeWebhookEvent('evt_mismatch'),
        );

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(PaymentStatus::Pending->value, $payment->fresh()->status);
    }

    public function test_failure_marks_payment_failed_and_order_payment_failed(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 1900);
        $payment = Payment::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'payment_method_type' => 'card',
            'status' => PaymentStatus::Pending->value,
            'currency' => 'AUD',
            'amount_cents' => 1900,
            'amount_received_cents' => 0,
            'amount_refunded_cents' => 0,
            'platform_fee_cents' => 0,
            'restaurant_share_cents' => 1900,
            'external_payment_intent_id' => 'pi_failed',
            'transfer_group' => 'order_'.$order->public_id,
        ]);

        app(StripeEventProcessor::class)->process(
            $this->stripeEvent('payment_intent.payment_failed', [
                'id' => 'pi_failed',
                'last_payment_error' => ['code' => 'card_declined'],
            ]),
            $this->makeWebhookEvent('evt_failed'),
        );

        $this->assertSame(PaymentStatus::Failed->value, $payment->fresh()->status);
        $this->assertSame('payment_failed', $order->fresh()->status);
    }

    public function test_refund_webhook_updates_payment(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2600, 'awaiting_restaurant');
        $payment = $this->createPaidPayment($order, [
            'external_payment_intent_id' => 'pi_refund_evt',
            'external_charge_id' => 'ch_refund_evt',
        ]);

        app(StripeEventProcessor::class)->process(
            $this->stripeEvent('refund.updated', [
                'id' => 're_webhook_1',
                'payment_intent' => 'pi_refund_evt',
                'amount' => 1000,
                'currency' => 'aud',
                'status' => 'succeeded',
            ]),
            $this->makeWebhookEvent('evt_refund'),
        );

        $payment->refresh();
        $this->assertSame(1000, $payment->amount_refunded_cents);
        $this->assertSame(PaymentStatus::PartiallyRefunded->value, $payment->status);
        $this->assertDatabaseHas('refunds', [
            'payment_id' => $payment->id,
            'external_refund_id' => 're_webhook_1',
            'status' => RefundStatus::Succeeded->value,
        ]);
    }

    public function test_dispute_created_records_dispute(): void
    {
        $customer = $this->paymentCustomer();
        $restaurant = $this->createPaymentRestaurant('first_party');
        $order = $this->createOnlineOrder($customer, $restaurant, 2800, 'awaiting_restaurant');
        $payment = $this->createPaidPayment($order, [
            'external_payment_intent_id' => 'pi_dispute',
            'external_charge_id' => 'ch_dispute',
        ]);

        app(StripeEventProcessor::class)->process(
            $this->stripeEvent('charge.dispute.created', [
                'id' => 'dp_test_1',
                'charge' => 'ch_dispute',
                'payment_intent' => 'pi_dispute',
                'status' => 'needs_response',
                'reason' => 'fraudulent',
                'amount' => 2800,
                'currency' => 'aud',
            ]),
            $this->makeWebhookEvent('evt_dispute'),
        );

        $this->assertDatabaseHas('payment_disputes', [
            'payment_id' => $payment->id,
            'external_dispute_id' => 'dp_test_1',
        ]);
        $this->assertSame(PaymentStatus::Disputed->value, $payment->fresh()->status);
        $this->assertSame(1, PaymentDispute::query()->where('payment_id', $payment->id)->count());
    }

    public function test_unknown_event_is_ignored(): void
    {
        $webhook = PaymentWebhookEvent::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'stripe',
            'external_event_id' => 'evt_unknown_type',
            'event_type' => 'customer.created',
            'payload_hash' => hash('sha256', 'evt_unknown_type'),
            'livemode' => false,
            'processing_status' => WebhookProcessingStatus::Received->value,
            'processing_attempts' => 0,
            'received_at' => now(),
            'sanitized_payload' => ['object' => []],
        ]);

        app(StripeEventProcessor::class)->process(
            $this->stripeEvent('customer.created', ['id' => 'cus_test']),
            $webhook,
        );

        $webhook->refresh();
        $this->assertSame(WebhookProcessingStatus::Ignored->value, $webhook->processing_status);
    }

  /**
   * @param  array<string, mixed>  $object
   */
    private function stripeEvent(string $type, array $object): StripeEvent
    {
        return StripeEvent::constructFrom([
            'id' => 'evt_'.md5($type.serialize($object)),
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }

    private function makeWebhookEvent(string $externalId, string $type = 'payment_intent.succeeded'): PaymentWebhookEvent
    {
        return PaymentWebhookEvent::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'stripe',
            'external_event_id' => $externalId,
            'event_type' => $type,
            'payload_hash' => hash('sha256', $externalId),
            'livemode' => false,
            'processing_status' => WebhookProcessingStatus::Received->value,
            'processing_attempts' => 0,
            'received_at' => now(),
            'sanitized_payload' => ['object' => []],
        ]);
    }
}
