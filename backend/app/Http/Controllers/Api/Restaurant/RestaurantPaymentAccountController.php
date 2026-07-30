<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Domain\Payments\Contracts\ConnectedAccountProvider;
use App\Http\Controllers\Controller;
use App\Models\RestaurantPaymentAccount;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;

class RestaurantPaymentAccountController extends Controller
{
    public function __construct(
        private readonly ConnectedAccountProvider $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request)->load('paymentAccount');

        if ($restaurant->isFirstParty()) {
            return ApiResponse::success([
                'payment_account' => [
                    'ownership_type' => 'first_party',
                    'requires_connected_account' => false,
                    'online_payments_enabled' => true,
                    'message' => 'First-party restaurants settle on the platform Stripe account.',
                ],
            ]);
        }

        return ApiResponse::success([
            'payment_account' => $this->resource($restaurant->paymentAccount),
        ]);
    }

    public function store(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);
        $result = $this->accounts->createAccount($restaurant);

        $this->audit->log(
            action: 'payment_account.created',
            actor: $request->user(),
            auditable: $restaurant,
            restaurantId: $restaurant->id,
            newValues: [
                'external_account_id' => $result->externalAccountId,
                'onboarding_status' => $result->onboardingStatus,
            ],
        );

        $account = RestaurantPaymentAccount::query()
            ->where('restaurant_id', $restaurant->id)
            ->first();

        return ApiResponse::success([
            'payment_account' => $this->resource($account),
        ], status: 201);
    }

    public function onboardingLink(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);
        $link = $this->accounts->createOnboardingLink($restaurant);

        $this->audit->log(
            action: 'payment_account.onboarding_link_created',
            actor: $request->user(),
            auditable: $restaurant,
            restaurantId: $restaurant->id,
        );

        return ApiResponse::success([
            'url' => $link->url,
            'expires_at' => $link->expiresAt?->toIso8601String(),
        ]);
    }

    public function refresh(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);
        $result = $this->accounts->refreshAccountStatus($restaurant);

        $account = RestaurantPaymentAccount::query()
            ->where('restaurant_id', $restaurant->id)
            ->first();

        return ApiResponse::success([
            'payment_account' => $this->resource($account),
            'onboarding_status' => $result->onboardingStatus,
        ]);
    }

    private function resource(?RestaurantPaymentAccount $account): ?array
    {
        if (! $account) {
            return [
                'ownership_type' => 'third_party',
                'requires_connected_account' => true,
                'onboarding_status' => 'not_started',
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'details_submitted' => false,
                'online_payments_enabled' => true,
            ];
        }

        return [
            'ownership_type' => 'third_party',
            'provider' => $account->provider,
            'onboarding_status' => $account->onboarding_status,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'online_payments_enabled' => $account->online_payments_enabled,
            'requirements_currently_due' => $account->requirements_currently_due,
            'requirements_eventually_due' => $account->requirements_eventually_due,
            'disabled_reason' => $account->disabled_reason,
            'country' => $account->country,
            'default_currency' => $account->default_currency,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'onboarding_completed_at' => $account->onboarding_completed_at?->toIso8601String(),
        ];
    }
}
