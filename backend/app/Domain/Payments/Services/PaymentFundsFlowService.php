<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\ChargeStrategy;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantPaymentAccount;
use App\Support\PaymentErrorResponse;

class PaymentFundsFlowService
{
    /**
     * @return array{
     *     strategy: string,
     *     platform_fee_cents: int,
     *     restaurant_share_cents: int,
     *     connected_account_id: string|null,
     *     ownership_type: string
     * }
     */
    public function resolveForOrder(Order $order): array
    {
        $order->loadMissing('restaurant.paymentAccount');

        $restaurant = $order->restaurant;
        if (! $restaurant) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                422,
            );
        }

        $ownershipType = (string) ($restaurant->ownership_type ?? 'third_party');

        if ($ownershipType === 'first_party' || $restaurant->isFirstParty()) {
            return [
                'strategy' => 'platform',
                'platform_fee_cents' => 0,
                'restaurant_share_cents' => (int) $order->total_cents,
                'connected_account_id' => null,
                'ownership_type' => 'first_party',
            ];
        }

        /** @var RestaurantPaymentAccount|null $account */
        $account = $restaurant->paymentAccount;

        if (! $account || ! $account->external_account_id) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                422,
            );
        }

        if ($account->disabled_reason) {
            throw new PaymentException(
                'CONNECTED_ACCOUNT_RESTRICTED',
                PaymentErrorResponse::messageForCode('CONNECTED_ACCOUNT_RESTRICTED'),
                422,
            );
        }

        if (! $account->charges_enabled || $account->onboarding_status !== 'active') {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                422,
            );
        }

        $platformFee = (int) $order->commission_amount_cents;
        $restaurantShare = (int) $order->total_cents - $platformFee;

        if ($restaurantShare < 0) {
            throw new PaymentException(
                'PAYMENT_AMOUNT_MISMATCH',
                PaymentErrorResponse::messageForCode('PAYMENT_AMOUNT_MISMATCH'),
                422,
            );
        }

        $strategy = (string) config('payments.connect_charge_strategy', ChargeStrategy::DestinationCharge->value);

        return [
            'strategy' => $strategy,
            'platform_fee_cents' => $platformFee,
            'restaurant_share_cents' => $restaurantShare,
            'connected_account_id' => $account->external_account_id,
            'ownership_type' => 'third_party',
        ];
    }

    /**
     * @return array{reverse_transfer: bool, refund_application_fee: bool}
     */
    public function refundFlagsForPayment(Payment $payment): array
    {
        $ownership = (string) (($payment->metadata['ownership_type'] ?? null) ?: '');
        $hasConnected = filled($payment->connected_account_id);

        if ($ownership === 'first_party' || ! $hasConnected) {
            return [
                'reverse_transfer' => false,
                'refund_application_fee' => false,
            ];
        }

        return [
            'reverse_transfer' => true,
            'refund_application_fee' => (int) $payment->platform_fee_cents > 0,
        ];
    }
}
