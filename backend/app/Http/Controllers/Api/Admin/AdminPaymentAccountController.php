<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Payments\Contracts\ConnectedAccountProvider;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantPaymentAccount;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminPaymentAccountController extends Controller
{
    public function __construct(
        private readonly ConnectedAccountProvider $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $query = RestaurantPaymentAccount::query()
            ->with(['restaurant:id,public_id,trading_name,slug,ownership_type'])
            ->orderByDesc('id');

        if ($request->filled('onboarding_status')) {
            $query->where('onboarding_status', $request->string('onboarding_status'));
        }
        if ($request->boolean('charges_enabled_only')) {
            $query->where('charges_enabled', true);
        }

        $accounts = $query->paginate(min(50, (int) $request->input('per_page', 25)));

        return ApiResponse::success([
            'payment_accounts' => $accounts->getCollection()->map(fn ($a) => $this->resource($a)),
        ], meta: [
            'current_page' => $accounts->currentPage(),
            'last_page' => $accounts->lastPage(),
            'total' => $accounts->total(),
            'per_page' => $accounts->perPage(),
        ]);
    }

    public function show(string $restaurantPublicId)
    {
        $restaurant = Restaurant::query()
            ->where('public_id', $restaurantPublicId)
            ->with('paymentAccount')
            ->firstOrFail();

        return ApiResponse::success([
            'restaurant' => [
                'public_id' => $restaurant->public_id,
                'trading_name' => $restaurant->trading_name,
                'ownership_type' => $restaurant->ownership_type,
            ],
            'payment_account' => $restaurant->isFirstParty()
                ? [
                    'ownership_type' => 'first_party',
                    'requires_connected_account' => false,
                    'online_payments_enabled' => true,
                ]
                : $this->resource($restaurant->paymentAccount, $restaurant),
        ]);
    }

    public function refresh(Request $request, string $restaurantPublicId)
    {
        $restaurant = Restaurant::query()->where('public_id', $restaurantPublicId)->firstOrFail();
        $result = $this->accounts->refreshAccountStatus($restaurant);

        $this->audit->log(
            action: 'payment_account.refreshed',
            actor: $request->user(),
            auditable: $restaurant,
            restaurantId: $restaurant->id,
            newValues: ['onboarding_status' => $result->onboardingStatus],
        );

        $account = RestaurantPaymentAccount::query()
            ->where('restaurant_id', $restaurant->id)
            ->first();

        return ApiResponse::success([
            'payment_account' => $this->resource($account, $restaurant),
        ]);
    }

    public function disableOnlinePayments(Request $request, string $restaurantPublicId)
    {
        $restaurant = Restaurant::query()->where('public_id', $restaurantPublicId)->firstOrFail();

        $account = RestaurantPaymentAccount::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'provider' => 'stripe',
                'onboarding_status' => 'not_started',
                'online_payments_enabled' => true,
            ],
        );

        $account->fill(['online_payments_enabled' => false])->save();

        $this->audit->log(
            action: 'payment_account.online_payments_disabled',
            actor: $request->user(),
            auditable: $restaurant,
            restaurantId: $restaurant->id,
        );

        return ApiResponse::success([
            'payment_account' => $this->resource($account->fresh(), $restaurant),
        ]);
    }

    private function resource(?RestaurantPaymentAccount $account, ?Restaurant $restaurant = null): ?array
    {
        if (! $account) {
            return null;
        }

        $restaurant ??= $account->restaurant;

        return [
            'restaurant_public_id' => $restaurant?->public_id,
            'restaurant_name' => $restaurant?->trading_name,
            'ownership_type' => $restaurant?->ownership_type,
            'provider' => $account->provider,
            'onboarding_status' => $account->onboarding_status,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'online_payments_enabled' => $account->online_payments_enabled,
            'requirements_currently_due' => $account->requirements_currently_due,
            'requirements_eventually_due' => $account->requirements_eventually_due,
            'disabled_reason' => $account->disabled_reason,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'onboarding_completed_at' => $account->onboarding_completed_at?->toIso8601String(),
        ];
    }
}
