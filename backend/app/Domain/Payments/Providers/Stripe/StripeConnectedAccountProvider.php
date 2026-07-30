<?php

namespace App\Domain\Payments\Providers\Stripe;

use App\Domain\Payments\Contracts\ConnectedAccountProvider;
use App\Domain\Payments\DTOs\ConnectedAccountResult;
use App\Domain\Payments\DTOs\OnboardingLinkResult;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Restaurant;
use App\Models\RestaurantPaymentAccount;
use App\Support\PaymentErrorResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Account;
use Stripe\Exception\ApiErrorException;

class StripeConnectedAccountProvider implements ConnectedAccountProvider
{
    public function __construct(
        private readonly StripeClientFactory $clientFactory,
    ) {}

    public function createAccount(Restaurant $restaurant): ConnectedAccountResult
    {
        if ($restaurant->isFirstParty()) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                'First-party restaurants do not use connected accounts.',
                422,
            );
        }

        return DB::transaction(function () use ($restaurant) {
            $account = RestaurantPaymentAccount::query()
                ->where('restaurant_id', $restaurant->id)
                ->lockForUpdate()
                ->first();

            if ($account?->external_account_id) {
                return $this->refreshAccountStatus($restaurant);
            }

            try {
                $stripeAccount = $this->clientFactory->make()->accounts->create([
                    'type' => 'express',
                    'country' => config('payments.platform_country', 'AU'),
                    'email' => $restaurant->business_email,
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                        'transfers' => ['requested' => true],
                    ],
                    'business_profile' => [
                        'name' => $restaurant->trading_name ?: $restaurant->legal_business_name,
                        'url' => $restaurant->website_url,
                    ],
                    'metadata' => [
                        'restaurant_public_id' => $restaurant->public_id,
                    ],
                ]);
            } catch (ApiErrorException $e) {
                throw new PaymentException(
                    'PAYMENT_CONFIGURATION_MISSING',
                    PaymentErrorResponse::messageForCode('PAYMENT_CONFIGURATION_MISSING'),
                    502,
                );
            }

            $result = $this->mapAccount($stripeAccount);

            RestaurantPaymentAccount::query()->updateOrCreate(
                ['restaurant_id' => $restaurant->id],
                [
                    'provider' => 'stripe',
                    'external_account_id' => $result->externalAccountId,
                    'account_type' => 'express',
                    'onboarding_status' => $result->onboardingStatus,
                    'charges_enabled' => $result->chargesEnabled,
                    'payouts_enabled' => $result->payoutsEnabled,
                    'details_submitted' => $result->detailsSubmitted,
                    'requirements_currently_due' => $result->requirementsCurrentlyDue,
                    'requirements_eventually_due' => $result->requirementsEventuallyDue,
                    'disabled_reason' => $result->disabledReason,
                    'country' => config('payments.platform_country', 'AU'),
                    'default_currency' => config('payments.currency', 'AUD'),
                    'last_synced_at' => now(),
                ],
            );

            return $result;
        });
    }

    public function createOnboardingLink(Restaurant $restaurant): OnboardingLinkResult
    {
        $account = $restaurant->paymentAccount
            ?? RestaurantPaymentAccount::query()->where('restaurant_id', $restaurant->id)->first();

        if (! $account?->external_account_id) {
            $this->createAccount($restaurant);
            $account = RestaurantPaymentAccount::query()->where('restaurant_id', $restaurant->id)->first();
        }

        if (! $account?->external_account_id) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                422,
            );
        }

        try {
            $link = $this->clientFactory->make()->accountLinks->create([
                'account' => $account->external_account_id,
                'refresh_url' => config('payments.stripe.onboarding_refresh_url'),
                'return_url' => config('payments.stripe.onboarding_return_url'),
                'type' => 'account_onboarding',
            ]);
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                502,
            );
        }

        return new OnboardingLinkResult(
            url: $link->url,
            expiresAt: isset($link->expires_at) ? Carbon::createFromTimestamp($link->expires_at) : null,
        );
    }

    public function refreshAccountStatus(Restaurant $restaurant): ConnectedAccountResult
    {
        $account = $restaurant->paymentAccount
            ?? RestaurantPaymentAccount::query()->where('restaurant_id', $restaurant->id)->first();

        if (! $account?->external_account_id) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                422,
            );
        }

        try {
            $stripeAccount = $this->clientFactory->make()->accounts->retrieve($account->external_account_id);
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'PAYMENT_ACCOUNT_NOT_READY',
                PaymentErrorResponse::messageForCode('PAYMENT_ACCOUNT_NOT_READY'),
                502,
            );
        }

        $result = $this->mapAccount($stripeAccount);

        $account->fill([
            'onboarding_status' => $result->onboardingStatus,
            'charges_enabled' => $result->chargesEnabled,
            'payouts_enabled' => $result->payoutsEnabled,
            'details_submitted' => $result->detailsSubmitted,
            'requirements_currently_due' => $result->requirementsCurrentlyDue,
            'requirements_eventually_due' => $result->requirementsEventuallyDue,
            'disabled_reason' => $result->disabledReason,
            'last_synced_at' => now(),
            'onboarding_completed_at' => $result->onboardingStatus === 'active'
                ? ($account->onboarding_completed_at ?? now())
                : $account->onboarding_completed_at,
        ])->save();

        return $result;
    }

    private function mapAccount(Account $account): ConnectedAccountResult
    {
        $currentlyDue = $account->requirements->currently_due ?? [];
        $eventuallyDue = $account->requirements->eventually_due ?? [];
        $disabledReason = $account->requirements->disabled_reason ?? null;

        $detailsSubmitted = (bool) ($account->details_submitted ?? false);
        $chargesEnabled = (bool) ($account->charges_enabled ?? false);
        $payoutsEnabled = (bool) ($account->payouts_enabled ?? false);

        $onboardingStatus = 'not_started';
        if ($disabledReason) {
            $onboardingStatus = 'restricted';
        } elseif ($chargesEnabled && $detailsSubmitted && empty($currentlyDue)) {
            $onboardingStatus = 'active';
        } elseif ($detailsSubmitted || ! empty($currentlyDue) || ! empty($eventuallyDue)) {
            $onboardingStatus = 'pending';
        }

        return new ConnectedAccountResult(
            externalAccountId: $account->id,
            onboardingStatus: $onboardingStatus,
            chargesEnabled: $chargesEnabled,
            payoutsEnabled: $payoutsEnabled,
            detailsSubmitted: $detailsSubmitted,
            requirementsCurrentlyDue: array_values(is_array($currentlyDue) ? $currentlyDue : iterator_to_array($currentlyDue)),
            requirementsEventuallyDue: array_values(is_array($eventuallyDue) ? $eventuallyDue : iterator_to_array($eventuallyDue)),
            disabledReason: $disabledReason,
        );
    }
}
