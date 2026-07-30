<?php

namespace App\Services\Checkout;

use App\Models\CheckoutQuote;
use App\Models\Restaurant;
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
    ) {}

    public function create(Request $request, array $input): array
    {
        $cart = $this->cartService->resolveCart($request);
        if (! $cart || $cart->items()->count() === 0) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
        }

        $fulfilment = $input['fulfilment_type'];
        $restaurant = $cart->restaurant;
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
                'fulfilment_type' => ['Third-party delivery is not available yet.'],
            ]);
        }

        if ($fulfilment === 'restaurant_delivery') {
            $addr = $input['address'] ?? [];
            $check = $this->serviceAreas->validateDeliveryAddress(
                $restaurant,
                $addr['postcode'] ?? null,
                isset($addr['latitude']) ? (float) $addr['latitude'] : null,
                isset($addr['longitude']) ? (float) $addr['longitude'] : null,
            );
            if (! $check['supported']) {
                throw ValidationException::withMessages([
                    'address' => [$check['message'] ?? 'This restaurant does not currently deliver to this address.'],
                ]);
            }
        }

        if ($fulfilment === 'pickup' && ! $restaurant->pickup_enabled) {
            throw ValidationException::withMessages(['fulfilment_type' => ['Pickup is not available.']]);
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

        return [
            'quote' => [
                'public_id' => $quote->public_id,
                'expires_at' => $quote->expires_at->toIso8601String(),
                'fulfilment_type' => $quote->fulfilment_type,
                'pricing' => $pricing,
                'warnings' => $warnings,
            ],
        ];
    }
}
