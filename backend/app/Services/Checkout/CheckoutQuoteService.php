<?php

namespace App\Services\Checkout;

use App\Models\CheckoutQuote;
use App\Services\Cart\CartBranchContext;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use App\Services\Restaurant\ServiceAreaValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutQuoteService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CartPricingService $pricing,
        private readonly ServiceAreaValidationService $serviceAreas,
        private readonly CartBranchContext $branchContext,
    ) {}

    public function create(Request $request, array $input): array
    {
        $cart = $this->cartService->resolveCart($request);
        if (! $cart || $cart->items()->count() === 0) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
        }

        $fulfilment = $input['fulfilment_type'];
        $restaurant = $cart->restaurant()->with(['business', 'branch'])->first();
        if (! $restaurant) {
            throw ValidationException::withMessages([
                'code' => ['CHECKOUT_BRANCH_UNAVAILABLE'],
                'cart' => ['Cart location is unavailable.'],
            ]);
        }

        $branchCheck = $this->branchContext->validateForOrdering($restaurant);
        if (! $branchCheck['ok']) {
            $code = match ($branchCheck['code']) {
                'CART_BRANCH_NOT_ACCEPTING_ORDERS' => 'CHECKOUT_BRANCH_NOT_ACCEPTING_ORDERS',
                'CART_BRANCH_RESTAURANT_MISMATCH' => 'CHECKOUT_CART_BRANCH_CHANGED',
                default => 'CHECKOUT_BRANCH_UNAVAILABLE',
            };
            throw ValidationException::withMessages([
                'code' => [$code],
                'cart' => [$branchCheck['message'] ?? 'This location cannot accept checkout.'],
            ]);
        }

        // Ignore any client-supplied branch/restaurant identifiers — cart lock is authoritative.
        // Read from the raw request so stripped/unvalidated client fields still cannot spoof context.
        $suppliedBranch = $request->input('branch_public_id') ?? $input['branch_public_id'] ?? null;
        $suppliedSlug = $request->input('restaurant_slug') ?? $input['restaurant_slug'] ?? null;
        $suppliedRestaurantId = $request->input('restaurant_id') ?? $input['restaurant_id'] ?? null;
        if ($suppliedBranch || $suppliedSlug || $suppliedRestaurantId) {
            $summary = $this->branchContext->summarize($restaurant);
            if (($suppliedBranch && $suppliedBranch !== ($summary['branch']['public_id'] ?? null))
                || ($suppliedSlug && $suppliedSlug !== $restaurant->slug)
                || ($suppliedRestaurantId && (string) $suppliedRestaurantId !== (string) $restaurant->public_id
                    && (string) $suppliedRestaurantId !== (string) $restaurant->id)) {
                throw ValidationException::withMessages([
                    'code' => ['CHECKOUT_CART_BRANCH_CHANGED'],
                    'cart' => ['Checkout must use the branch locked to your cart.'],
                ]);
            }
        }

        $pricing = $this->pricing->calculate($cart, true);

        if (! $pricing['minimum_order_met']) {
            throw ValidationException::withMessages(['cart' => ['Minimum order not met.']]);
        }

        $warnings = $pricing['warnings'];
        foreach ($warnings as $w) {
            if ($w['code'] === 'ITEM_UNAVAILABLE') {
                throw ValidationException::withMessages(['cart' => ['Cart contains unavailable items.']]);
            }
        }

        if ($fulfilment === 'third_party_delivery') {
            throw ValidationException::withMessages([
                'code' => ['CHECKOUT_FULFILMENT_UNAVAILABLE'],
                'fulfilment_type' => ['Third-party delivery is not available yet.'],
            ]);
        }

        if ($fulfilment === 'restaurant_delivery') {
            if (! $restaurant->restaurant_delivery_enabled) {
                throw ValidationException::withMessages([
                    'code' => ['CHECKOUT_FULFILMENT_UNAVAILABLE'],
                    'fulfilment_type' => ['Delivery is not available for this location.'],
                ]);
            }
            $addr = $input['address'] ?? [];
            $check = $this->serviceAreas->validateDeliveryAddress(
                $restaurant,
                $addr['postcode'] ?? null,
                isset($addr['latitude']) ? (float) $addr['latitude'] : null,
                isset($addr['longitude']) ? (float) $addr['longitude'] : null,
            );
            if (! $check['supported']) {
                throw ValidationException::withMessages([
                    'code' => ['CHECKOUT_ADDRESS_OUTSIDE_SERVICE_AREA'],
                    'address' => [$check['message'] ?? 'This location does not currently deliver to this address.'],
                ]);
            }
        }

        if ($fulfilment === 'pickup' && ! $restaurant->pickup_enabled) {
            throw ValidationException::withMessages([
                'code' => ['CHECKOUT_FULFILMENT_UNAVAILABLE'],
                'fulfilment_type' => ['Pickup is not available.'],
            ]);
        }

        $expires = now()->addMinutes(config('checkout.quote_expiry_minutes'));
        $quote = CheckoutQuote::query()->create([
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'customer_id' => $request->user()?->id,
            'restaurant_id' => $restaurant->id,
            'fulfilment_type' => $fulfilment,
            'address_snapshot' => $input['address'] ?? null,
            'pricing_snapshot' => $pricing,
            'warnings' => $warnings,
            'expires_at' => $expires,
        ]);

        $summary = $this->branchContext->summarize($restaurant);

        return [
            'quote' => [
                'public_id' => $quote->public_id,
                'expires_at' => $quote->expires_at->toIso8601String(),
                'fulfilment_type' => $quote->fulfilment_type,
                'pricing' => $pricing,
                'warnings' => $warnings,
                'business' => $summary['business'],
                'branch' => $summary['branch'],
                'restaurant' => $summary['restaurant'],
            ],
        ];
    }
}
