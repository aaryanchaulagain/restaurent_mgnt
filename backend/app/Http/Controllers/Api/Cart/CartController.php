<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\Cart\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService) {}

    public function show(Request $request)
    {
        $cart = $this->cartService->resolveCart($request);

        return ApiResponse::success($this->cartService->cartPayload($cart));
    }

    public function storeItem(Request $request)
    {
        $data = $request->validate([
            'menu_item_public_id' => ['required', 'uuid'],
            'variant_public_id' => ['nullable', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'modifier_option_public_ids' => ['nullable', 'array'],
            'modifier_option_public_ids.*' => ['uuid'],
            'special_instructions' => ['nullable', 'string', 'max:500'],
            'replace_restaurant' => ['sometimes', 'boolean'],
            'client_price_cents' => ['nullable', 'integer'],
        ]);

        try {
            $payload = $this->cartService->addItem($request, $data);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['code']) && in_array('CART_RESTAURANT_CONFLICT', $errors['code'], true)) {
                $item = MenuItem::query()->where('public_id', $data['menu_item_public_id'])->with('restaurant')->first();
                $cart = $this->cartService->resolveCart($request);

                return ApiResponse::error(
                    'Your cart contains items from another restaurant.',
                    409,
                    ['code' => ['CART_RESTAURANT_CONFLICT']],
                    [
                        'current_restaurant' => $cart ? [
                            'slug' => $cart->restaurant->slug,
                            'trading_name' => $cart->restaurant->trading_name,
                        ] : null,
                        'requested_restaurant' => $item ? [
                            'slug' => $item->restaurant->slug,
                            'trading_name' => $item->restaurant->trading_name,
                        ] : null,
                    ],
                );
            }
            throw $e;
        }

        $json = ApiResponse::success($payload, status: 201);
        if (! $request->user() && ! empty($payload['cart']['public_id'])) {
            $cart = \App\Models\Cart::query()->where('public_id', $payload['cart']['public_id'])->firstOrFail();
            if (! $cart->token_hash) {
                $json->headers->setCookie($this->cartService->makeGuestCookie($cart));
            }
        }

        return $json;
    }

    public function updateItem(Request $request, string $publicId)
    {
        $data = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'special_instructions' => ['nullable', 'string', 'max:500'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        return ApiResponse::success($this->cartService->updateItem($request, $publicId, $data));
    }

    public function destroyItem(Request $request, string $publicId)
    {
        return ApiResponse::success($this->cartService->removeItem($request, $publicId));
    }

    public function destroy(Request $request)
    {
        return ApiResponse::success($this->cartService->clear($request));
    }

    public function replaceRestaurant(Request $request)
    {
        $data = $request->validate([
            'restaurant_slug' => ['required', 'string'],
        ]);
        $restaurant = Restaurant::query()->where('slug', $data['restaurant_slug'])->firstOrFail();

        return ApiResponse::success($this->cartService->replaceRestaurant($request, $restaurant->id));
    }

    public function validateCart(Request $request)
    {
        $cart = $this->cartService->resolveCart($request);

        return ApiResponse::success($this->cartService->cartPayload($cart, true));
    }

    public function merge(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Authentication required.', 401);
        }
        $data = $request->validate([
            'strategy' => ['required', 'in:merge,keep_guest,keep_customer'],
        ]);
        $strategy = $data['strategy'] === 'keep_customer' ? 'keep_customer' : ($data['strategy'] === 'keep_guest' ? 'keep_guest' : 'merge');

        return ApiResponse::success($this->cartService->mergeGuestIntoUser($request, $user, $strategy));
    }
}
