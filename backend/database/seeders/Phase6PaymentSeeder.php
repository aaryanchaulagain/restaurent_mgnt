<?php

namespace Database\Seeders;

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Enums\WebhookProcessingStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\RestaurantPaymentAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class Phase6PaymentSeeder extends Seeder
{
  private const FIXTURE_META = ['fixture' => true, 'phase' => 6];

  public function run(): void
  {
    $customer = User::query()->where('email', 'customer@example.com')->first();
    $suvakamana = Restaurant::query()->where('slug', config('suvakamana.platform_restaurant_slug'))->first();
    $partner = Restaurant::query()->where('slug', 'golden-wok')->first();

    if (! $suvakamana || ! $partner || ! $customer) {
      return;
    }

    $partner->update(['ownership_type' => 'third_party']);

    $suvItem = MenuItem::query()
      ->where('restaurant_id', $suvakamana->id)
      ->where('is_available', true)
      ->orderBy('id')
      ->first();
    $partnerItem = MenuItem::query()
      ->where('restaurant_id', $partner->id)
      ->where('is_available', true)
      ->orderBy('id')
      ->first();

    if (! $suvItem || ! $partnerItem) {
      return;
    }

    $this->seedConnectedAccountFixtures($partner);

    $amountSuv = 2500;
    $amountPartner = 3990;
    $commissionPartner = (int) round($amountPartner * 0.125);

    // 1. First-party pending payment
    $this->seedPaymentScenario(1, 'PAY-SEED-000001', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'pending_payment',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Pending->value,
      'pay_status' => PaymentStatus::Pending->value,
      'external_pi' => 'pi_seed_000001',
    ]);

    // 2. First-party paid
    $this->seedPaymentScenario(2, 'PAY-SEED-000002', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Paid->value,
      'pay_status' => PaymentStatus::Paid->value,
      'external_pi' => 'pi_seed_000002',
      'paid' => true,
    ]);

    // 3. Third-party pending
    $this->seedPaymentScenario(3, 'PAY-SEED-000003', $partner, $customer, $partnerItem, $amountPartner, 0.125, [
      'order_status' => 'pending_payment',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Pending->value,
      'pay_status' => PaymentStatus::Pending->value,
      'external_pi' => 'pi_seed_000003',
      'platform_fee_cents' => $commissionPartner,
      'connected_account_id' => 'acct_seed_golden_wok_active',
    ]);

    // 4. Third-party paid
    $this->seedPaymentScenario(4, 'PAY-SEED-000004', $partner, $customer, $partnerItem, $amountPartner, 0.125, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Paid->value,
      'pay_status' => PaymentStatus::Paid->value,
      'external_pi' => 'pi_seed_000004',
      'paid' => true,
      'platform_fee_cents' => $commissionPartner,
      'connected_account_id' => 'acct_seed_golden_wok_active',
    ]);

    // 5. Failed payment
    $this->seedPaymentScenario(5, 'PAY-SEED-000005', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'payment_failed',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Failed->value,
      'pay_status' => PaymentStatus::Failed->value,
      'external_pi' => 'pi_seed_000005',
      'attempt_status' => PaymentAttemptStatus::Failed->value,
      'last_error_code' => 'card_declined',
    ]);

    // 6. Requires-action payment
    $this->seedPaymentScenario(6, 'PAY-SEED-000006', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'pending_payment',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::RequiresAction->value,
      'pay_status' => PaymentStatus::RequiresAction->value,
      'external_pi' => 'pi_seed_000006',
      'attempt_status' => PaymentAttemptStatus::RequiresAction->value,
      'requires_action' => true,
    ]);

    // 7. Processing payment
    $this->seedPaymentScenario(7, 'PAY-SEED-000007', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'pending_payment',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Processing->value,
      'pay_status' => PaymentStatus::Processing->value,
      'external_pi' => 'pi_seed_000007',
      'attempt_status' => PaymentAttemptStatus::Processing->value,
    ]);

    // 8. Cancelled payment
    $this->seedPaymentScenario(8, 'PAY-SEED-000008', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'payment_failed',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Cancelled->value,
      'pay_status' => PaymentStatus::Cancelled->value,
      'external_pi' => 'pi_seed_000008',
      'attempt_status' => PaymentAttemptStatus::Cancelled->value,
      'cancelled' => true,
    ]);

    // 9. Full refund
    $payment9 = $this->seedPaymentScenario(9, 'PAY-SEED-000009', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Refunded->value,
      'pay_status' => PaymentStatus::Refunded->value,
      'external_pi' => 'pi_seed_000009',
      'paid' => true,
      'amount_refunded_cents' => $amountSuv,
    ]);
    $this->seedRefund($payment9, $amountSuv, RefundStatus::Succeeded->value, 're_seed_000009');

    // 10. Partial refund
    $partial = (int) round($amountSuv / 2);
    $payment10 = $this->seedPaymentScenario(10, 'PAY-SEED-000010', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::PartiallyRefunded->value,
      'pay_status' => PaymentStatus::PartiallyRefunded->value,
      'external_pi' => 'pi_seed_000010',
      'paid' => true,
      'amount_refunded_cents' => $partial,
    ]);
    $this->seedRefund($payment10, $partial, RefundStatus::Succeeded->value, 're_seed_000010');

    // 11. Failed refund
    $payment11 = $this->seedPaymentScenario(11, 'PAY-SEED-000011', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Paid->value,
      'pay_status' => PaymentStatus::Paid->value,
      'external_pi' => 'pi_seed_000011',
      'paid' => true,
    ]);
    $this->seedRefund($payment11, $amountSuv, RefundStatus::Failed->value, 're_seed_000011');

    // 12. Disputed payment
    $payment12 = $this->seedPaymentScenario(12, 'PAY-SEED-000012', $partner, $customer, $partnerItem, $amountPartner, 0.125, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Disputed->value,
      'pay_status' => PaymentStatus::Disputed->value,
      'external_pi' => 'pi_seed_000012',
      'paid' => true,
      'platform_fee_cents' => $commissionPartner,
      'connected_account_id' => 'acct_seed_golden_wok_active',
    ]);
    PaymentDispute::query()->updateOrCreate(
      ['external_dispute_id' => 'dp_seed_000012'],
      [
        'public_id' => $this->fixtureUuid(12012),
        'payment_id' => $payment12->id,
        'order_id' => $payment12->order_id,
        'status' => 'needs_response',
        'reason' => 'fraudulent',
        'amount_cents' => $amountPartner,
        'currency' => 'AUD',
        'metadata' => array_merge(self::FIXTURE_META, ['scenario' => 12]),
      ],
    );

    // 13–15: connected account rows (orders optional; accounts seeded in seedConnectedAccountFixtures)

    // 16. Duplicate webhook fixture (paid payment + processed event)
    $payment16 = $this->seedPaymentScenario(16, 'PAY-SEED-000016', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'awaiting_restaurant',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Paid->value,
      'pay_status' => PaymentStatus::Paid->value,
      'external_pi' => 'pi_seed_000016',
      'paid' => true,
    ]);
    PaymentWebhookEvent::query()->updateOrCreate(
      ['provider' => 'stripe', 'external_event_id' => 'evt_seed_duplicate_000016'],
      [
        'public_id' => $this->fixtureUuid(160),
        'event_type' => 'payment_intent.succeeded',
        'payload_hash' => hash('sha256', 'evt_seed_duplicate_000016'),
        'livemode' => false,
        'processing_status' => WebhookProcessingStatus::Processed->value,
        'processing_attempts' => 1,
        'received_at' => now()->subHour(),
        'processed_at' => now()->subHour(),
        'related_payment_id' => $payment16->id,
        'related_order_id' => $payment16->order_id,
        'sanitized_payload' => [
          'id' => 'evt_seed_duplicate_000016',
          'type' => 'payment_intent.succeeded',
          'object' => ['id' => 'pi_seed_000016', 'status' => 'succeeded'],
        ],
      ],
    );

    // 17. Failed webhook retry fixture
    $payment17 = $this->seedPaymentScenario(17, 'PAY-SEED-000017', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'pending_payment',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Processing->value,
      'pay_status' => PaymentStatus::Processing->value,
      'external_pi' => 'pi_seed_000017',
    ]);
    PaymentWebhookEvent::query()->updateOrCreate(
      ['provider' => 'stripe', 'external_event_id' => 'evt_seed_failed_000017'],
      [
        'public_id' => $this->fixtureUuid(170),
        'event_type' => 'payment_intent.succeeded',
        'payload_hash' => hash('sha256', 'evt_seed_failed_000017'),
        'livemode' => false,
        'processing_status' => WebhookProcessingStatus::Failed->value,
        'processing_attempts' => 2,
        'received_at' => now()->subMinutes(30),
        'failed_at' => now()->subMinutes(25),
        'last_error' => 'PAYMENT_AMOUNT_MISMATCH',
        'related_payment_id' => $payment17->id,
        'related_order_id' => $payment17->order_id,
        'sanitized_payload' => [
          'id' => 'evt_seed_failed_000017',
          'type' => 'payment_intent.succeeded',
          'object' => ['id' => 'pi_seed_000017', 'status' => 'succeeded', 'amount' => 1],
        ],
      ],
    );

    // 18. Amount mismatch anomaly
    $this->seedPaymentScenario(18, 'PAY-SEED-000018', $suvakamana, $customer, $suvItem, $amountSuv, 0.0, [
      'order_status' => 'pending_payment',
      'payment_method' => 'online_card',
      'payment_status' => PaymentStatus::Pending->value,
      'pay_status' => PaymentStatus::Pending->value,
      'external_pi' => 'pi_seed_000018',
      'payment_amount_override' => $amountSuv + 100,
      'scenario_note' => 'amount_mismatch_anomaly',
    ]);
  }

  private function seedConnectedAccountFixtures(Restaurant $goldenWok): void
  {
    RestaurantPaymentAccount::query()->updateOrCreate(
      ['restaurant_id' => $goldenWok->id],
      [
        'provider' => 'stripe',
        'external_account_id' => 'acct_seed_golden_wok_active',
        'account_type' => 'express',
        'onboarding_status' => 'active',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'online_payments_enabled' => true,
        'requirements_currently_due' => [],
        'requirements_eventually_due' => [],
        'disabled_reason' => null,
        'country' => 'AU',
        'default_currency' => 'AUD',
        'last_synced_at' => now(),
        'onboarding_completed_at' => now()->subDays(30),
      ],
    );

    $requirementsDue = Restaurant::query()->where('slug', 'night-owl')->first();
    if ($requirementsDue) {
      $requirementsDue->update(['ownership_type' => 'third_party']);
      RestaurantPaymentAccount::query()->updateOrCreate(
        ['restaurant_id' => $requirementsDue->id],
        [
          'provider' => 'stripe',
          'external_account_id' => 'acct_seed_requirements_due',
          'account_type' => 'express',
          'onboarding_status' => 'pending',
          'charges_enabled' => false,
          'payouts_enabled' => false,
          'details_submitted' => true,
          'online_payments_enabled' => true,
          'requirements_currently_due' => ['individual.verification.document'],
          'requirements_eventually_due' => ['tos_acceptance.date'],
          'disabled_reason' => null,
          'country' => 'AU',
          'default_currency' => 'AUD',
          'last_synced_at' => now(),
        ],
      );
    }

    $restricted = Restaurant::query()->where('slug', 'pickup-only')->first();
    if ($restricted) {
      $restricted->update(['ownership_type' => 'third_party']);
      RestaurantPaymentAccount::query()->updateOrCreate(
        ['restaurant_id' => $restricted->id],
        [
          'provider' => 'stripe',
          'external_account_id' => 'acct_seed_restricted',
          'account_type' => 'express',
          'onboarding_status' => 'restricted',
          'charges_enabled' => false,
          'payouts_enabled' => false,
          'details_submitted' => true,
          'online_payments_enabled' => false,
          'requirements_currently_due' => ['individual.verification.document'],
          'requirements_eventually_due' => [],
          'disabled_reason' => 'requirements.past_due',
          'country' => 'AU',
          'default_currency' => 'AUD',
          'last_synced_at' => now(),
        ],
      );
    }
  }

  /**
   * @param  array<string, mixed>  $options
   */
  private function seedPaymentScenario(
    int $scenario,
    string $orderNumber,
    Restaurant $restaurant,
    User $customer,
    MenuItem $menuItem,
    int $totalCents,
    float $commissionRate,
    array $options = [],
  ): Payment {
    $commissionAmount = $commissionRate > 0 ? (int) round($totalCents * $commissionRate) : 0;
    $orderStatus = (string) ($options['order_status'] ?? 'pending_payment');
    $paymentMethod = (string) ($options['payment_method'] ?? 'online_card');
    $orderPaymentStatus = (string) ($options['payment_status'] ?? PaymentStatus::Pending->value);

    $order = Order::query()->firstOrNew(['order_number' => $orderNumber]);
    if (! $order->exists) {
      $order->public_id = $this->fixtureUuid($scenario);
    }

    $placedAt = now()->subMinutes(45);

    $order->fill([
      'restaurant_id' => $restaurant->id,
      'customer_id' => $customer->id,
      'status' => $orderStatus,
      'payment_method' => $paymentMethod,
      'payment_status' => $orderPaymentStatus,
      'fulfilment_type' => 'pickup',
      'currency' => 'AUD',
      'customer_name_snapshot' => $customer->name,
      'customer_email_snapshot' => $customer->email,
      'customer_phone_snapshot' => '+61400000000',
      'subtotal_cents' => $totalCents,
      'discount_cents' => 0,
      'total_cents' => $totalCents,
      'commission_rate_snapshot' => $commissionRate,
      'commission_amount_cents' => $commissionAmount,
      'restaurant_net_estimate_cents' => max(0, $totalCents - $commissionAmount),
      'placed_at' => $placedAt,
      'expires_at' => $orderStatus === 'awaiting_restaurant' ? now()->addMinutes(10) : now()->addMinutes(30),
      'idempotency_key' => 'seed-'.$orderNumber,
    ]);
    $order->save();

    $order->items()->delete();
    OrderItem::query()->create([
      'public_id' => (string) Str::uuid(),
      'order_id' => $order->id,
      'menu_item_id' => $menuItem->id,
      'item_name_snapshot' => $menuItem->name,
      'item_description_snapshot' => $menuItem->short_description,
      'unit_price_cents' => $totalCents,
      'quantity' => 1,
      'line_subtotal_cents' => $totalCents,
      'line_total_cents' => $totalCents,
    ]);

    $payStatus = (string) ($options['pay_status'] ?? PaymentStatus::Pending->value);
    $paymentAmount = (int) ($options['payment_amount_override'] ?? $totalCents);
    $platformFee = (int) ($options['platform_fee_cents'] ?? $commissionAmount);
    $restaurantShare = max(0, $paymentAmount - $platformFee);
    $ownership = $restaurant->isFirstParty() ? 'first_party' : 'third_party';
    $strategy = $ownership === 'first_party' ? 'platform' : (string) config('payments.connect_charge_strategy', 'destination_charge');

    $payment = Payment::query()->firstOrNew(['order_id' => $order->id]);
    if (! $payment->exists) {
      $payment->public_id = $this->fixtureUuid(1000 + $scenario);
    }

    $paid = (bool) ($options['paid'] ?? false);
    $externalPi = (string) ($options['external_pi'] ?? ('pi_seed_'.str_pad((string) $scenario, 6, '0', STR_PAD_LEFT)));

    $payment->fill([
      'restaurant_id' => $restaurant->id,
      'customer_id' => $customer->id,
      'provider' => 'stripe',
      'payment_method_type' => 'card',
      'status' => $payStatus,
      'currency' => 'AUD',
      'amount_cents' => $paymentAmount,
      'amount_received_cents' => $paid ? $totalCents : 0,
      'amount_refunded_cents' => (int) ($options['amount_refunded_cents'] ?? 0),
      'platform_fee_cents' => $platformFee,
      'restaurant_share_cents' => $restaurantShare,
      'external_payment_intent_id' => $externalPi,
      'external_charge_id' => $paid ? ('ch_seed_'.str_pad((string) $scenario, 6, '0', STR_PAD_LEFT)) : null,
      'connected_account_id' => $options['connected_account_id'] ?? null,
      'transfer_group' => 'order_'.$order->public_id,
      'paid_at' => $paid ? now()->subMinutes(20) : null,
      'failed_at' => $payStatus === PaymentStatus::Failed->value ? now()->subMinutes(15) : null,
      'cancelled_at' => ! empty($options['cancelled']) ? now()->subMinutes(15) : null,
      'last_error_code' => $options['last_error_code'] ?? null,
      'last_error_message' => isset($options['last_error_code']) ? 'Payment failed' : null,
      'metadata' => array_merge(self::FIXTURE_META, [
        'scenario' => $scenario,
        'ownership_type' => $ownership,
        'strategy' => $strategy,
        'scenario_note' => $options['scenario_note'] ?? null,
      ]),
    ]);
    $payment->save();

    $payment->attempts()->delete();
    PaymentAttempt::query()->create([
      'public_id' => (string) Str::uuid(),
      'payment_id' => $payment->id,
      'order_id' => $order->id,
      'attempt_number' => 1,
      'idempotency_key' => 'seed:payment:'.$orderNumber.':1',
      'request_payload_hash' => hash('sha256', $orderNumber),
      'status' => $options['attempt_status'] ?? PaymentAttemptStatus::Pending->value,
      'external_payment_intent_id' => $externalPi,
      'amount_cents' => $paymentAmount,
      'currency' => 'AUD',
      'requires_action' => (bool) ($options['requires_action'] ?? false),
      'failure_code' => $options['last_error_code'] ?? null,
      'started_at' => $placedAt,
      'completed_at' => in_array($payStatus, [PaymentStatus::Paid->value, PaymentStatus::Failed->value, PaymentStatus::Cancelled->value], true)
        ? now()->subMinutes(20)
        : null,
      'expires_at' => now()->addMinutes(30),
    ]);

    return $payment->fresh();
  }

  private function seedRefund(Payment $payment, int $amountCents, string $status, string $externalRefundId): void
  {
    Refund::query()->updateOrCreate(
      ['idempotency_key' => 'seed-refund:'.$payment->public_id],
      [
        'public_id' => (string) Str::uuid(),
        'payment_id' => $payment->id,
        'order_id' => $payment->order_id,
        'restaurant_id' => $payment->restaurant_id,
        'provider' => 'stripe',
        'external_refund_id' => $externalRefundId,
        'status' => $status,
        'amount_cents' => $amountCents,
        'currency' => $payment->currency,
        'reason_category' => 'customer_request',
        'refund_application_fee' => true,
        'reverse_transfer' => (bool) $payment->connected_account_id,
        'requested_at' => now()->subDay(),
        'completed_at' => $status === RefundStatus::Succeeded->value ? now()->subHours(12) : null,
        'failed_at' => $status === RefundStatus::Failed->value ? now()->subHours(6) : null,
        'provider_failure_code' => $status === RefundStatus::Failed->value ? 'insufficient_funds' : null,
      ],
    );
  }

  private function fixtureUuid(int $n): string
  {
    return sprintf('550e8400-e29b-41d4-a716-%012x', $n);
  }
}
