<?php

namespace App\Services\Cart;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemModifier;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(
        private readonly CartPricingService $pricing,
    ) {}

    public function resolveCart(Request $request): ?Cart
    {
        $user = $request->user();
        if ($user) {
            $cart = Cart::query()
                ->where('customer_id', $user->id)
                ->where('status', 'active')
                ->with(['items.modifiers', 'restaurant'])
                ->first();
            if ($cart) {
                return $cart;
            }
        }

        $token = $request->cookies->get(config('cart.cookie_name'));
        if (! is_string($token) || $token === '') {
            $token = $request->cookie(config('cart.cookie_name'));
        }
        if (! is_string($token) || $token === '') {
            return null;
        }

        $hash = hash('sha256', $token);

        return Cart::query()
            ->where('token_hash', $hash)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with(['items.modifiers', 'restaurant'])
            ->first();
    }

    public function cartPayload(?Cart $cart, bool $detectChanges = true): array
    {
        if (! $cart) {
            return [
                'cart' => null,
                'pricing' => null,
            ];
        }

        $pricing = $this->pricing->calculate($cart, $detectChanges);

        return [
            'cart' => $this->serializeCart($cart),
            'pricing' => $pricing,
        ];
    }

    public function addItem(Request $request, array $input): array
    {
        $item = MenuItem::query()
            ->where('public_id', $input['menu_item_public_id'])
            ->firstOrFail();

        $restaurant = $this->assertRestaurantOrderable($item->restaurant_id);
        $cart = $this->resolveOrCreateCart($request, $restaurant, $input['replace_restaurant'] ?? false);

        if ($cart->restaurant_id !== $restaurant->id) {
            throw ValidationException::withMessages([
                'restaurant' => ['Cart restaurant conflict.'],
            ]);
        }

        $this->validateItem($item, $input);

        $line = DB::transaction(function () use ($cart, $item, $input) {
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $variant = null;
            if (! empty($input['variant_public_id'])) {
                $variant = MenuItemVariant::query()
                    ->where('restaurant_id', $item->restaurant_id)
                    ->where('menu_item_id', $item->id)
                    ->where('public_id', $input['variant_public_id'])
                    ->firstOrFail();
            }

            $unit = $variant ? (int) $variant->price_cents : (int) $item->base_price_cents;
            $line = CartItem::query()->create([
                'public_id' => (string) Str::uuid(),
                'cart_id' => $cart->id,
                'menu_item_id' => $item->id,
                'menu_item_variant_id' => $variant?->id,
                'quantity' => min(config('cart.max_quantity_per_line'), max(1, (int) $input['quantity'])),
                'special_instructions' => $input['special_instructions'] ?? null,
                'unit_price_snapshot_cents' => $unit,
                'estimated_total_cents' => $unit * max(1, (int) $input['quantity']),
            ]);

            $this->syncModifiers($line, $item, $input['modifier_option_public_ids'] ?? []);
            $cart->increment('version');
            $cart->touch();

            return $line->load('modifiers');
        });

        $cart = $cart->fresh(['items.modifiers', 'restaurant']);
        $response = $this->cartPayload($cart);
        $response['cart_item_public_id'] = $line->public_id;

        return $response;
    }

    public function updateItem(Request $request, string $linePublicId, array $input): array
    {
        $cart = $this->requireCart($request);
        $line = CartItem::query()->where('cart_id', $cart->id)->where('public_id', $linePublicId)->firstOrFail();

        DB::transaction(function () use ($cart, $line, $input) {
            if (isset($input['quantity'])) {
                $line->quantity = min(config('cart.max_quantity_per_line'), max(1, (int) $input['quantity']));
            }
            if (array_key_exists('special_instructions', $input)) {
                $line->special_instructions = $input['special_instructions'];
            }
            $line->save();
            $cart->increment('version');
        });

        return $this->cartPayload($cart->fresh(['items.modifiers', 'restaurant']));
    }

    public function removeItem(Request $request, string $linePublicId): array
    {
        $cart = $this->requireCart($request);
        CartItem::query()->where('cart_id', $cart->id)->where('public_id', $linePublicId)->delete();
        $cart->increment('version');

        return $this->cartPayload($cart->fresh(['items.modifiers', 'restaurant']));
    }

    public function clear(Request $request): array
    {
        $cart = $this->resolveCart($request);
        if ($cart) {
            $cart->items()->delete();
            $cart->update(['status' => 'abandoned']);
        }

        return ['cart' => null, 'pricing' => null];
    }

    public function replaceRestaurant(Request $request, int $newRestaurantId): array
    {
        $cart = $this->resolveCart($request);
        if ($cart) {
            $cart->items()->delete();
            $cart->update(['status' => 'abandoned']);
        }

        $this->assertRestaurantOrderable($newRestaurantId);
        $newCart = $this->createCartShell($request, Restaurant::query()->findOrFail($newRestaurantId));

        return $this->cartPayload($newCart);
    }

    public function mergeGuestIntoUser(Request $request, User $user, string $strategy): array
    {
        $guest = $this->resolveCart($request);
        $customerCart = Cart::query()
            ->where('customer_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $guest) {
            return $this->cartPayload($customerCart?->load(['items.modifiers', 'restaurant']));
        }

        if (! $customerCart) {
            $guest->update(['customer_id' => $user->id, 'token_hash' => null]);
            Cookie::queue(Cookie::forget(config('cart.cookie_name')));

            return $this->cartPayload($guest->fresh(['items.modifiers', 'restaurant']));
        }

        if ($guest->restaurant_id === $customerCart->restaurant_id && $strategy === 'merge') {
            foreach ($guest->items as $item) {
                $item->update(['cart_id' => $customerCart->id]);
            }
            $guest->update(['status' => 'abandoned']);
            Cookie::queue(Cookie::forget(config('cart.cookie_name')));

            return $this->cartPayload($customerCart->fresh(['items.modifiers', 'restaurant']));
        }

        if ($strategy === 'keep_guest') {
            $customerCart->update(['status' => 'abandoned']);
            $guest->update(['customer_id' => $user->id, 'token_hash' => null]);
            Cookie::queue(Cookie::forget(config('cart.cookie_name')));

            return $this->cartPayload($guest->fresh(['items.modifiers', 'restaurant']));
        }

        $guest->update(['status' => 'abandoned']);
        Cookie::queue(Cookie::forget(config('cart.cookie_name')));

        return $this->cartPayload($customerCart->fresh(['items.modifiers', 'restaurant']));
    }

    public function makeGuestCookie(Cart $cart): \Symfony\Component\HttpFoundation\Cookie
    {
        $token = Str::random(64);
        $cart->update(['token_hash' => hash('sha256', $token)]);

        return cookie(
            config('cart.cookie_name'),
            $token,
            60 * 24 * config('cart.guest_ttl_days'),
            '/',
            null,
            app()->environment('production'),
            true,
            false,
            'lax',
        );
    }

    private function resolveOrCreateCart(Request $request, Restaurant $restaurant, bool $replace): Cart
    {
        $existing = $this->resolveCart($request);
        if ($existing && $existing->restaurant_id !== $restaurant->id) {
            if (! $replace) {
                throw ValidationException::withMessages([
                    'code' => ['CART_RESTAURANT_CONFLICT'],
                ]);
            }
            $existing->items()->delete();
            $existing->update(['status' => 'abandoned']);
            $existing = null;
        }

        if ($existing) {
            return $existing;
        }

        return $this->createCartShell($request, $restaurant);
    }

    private function createCartShell(Request $request, Restaurant $restaurant): Cart
    {
        $user = $request->user();
        $cart = Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user?->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'active',
            'currency' => $restaurant->currency ?? 'AUD',
            'expires_at' => $user ? null : now()->addDays(config('cart.guest_ttl_days')),
        ]);

        if (! $user) {
            // Cookie attached on first item add response.
        }

        return $cart;
    }

    private function requireCart(Request $request): Cart
    {
        $cart = $this->resolveCart($request);
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => ['No active cart.']]);
        }

        return $cart;
    }

    private function assertRestaurantOrderable(int $restaurantId): Restaurant
    {
        $restaurant = Restaurant::query()->findOrFail($restaurantId);
        if ($restaurant->status !== RestaurantStatus::Active || ! $restaurant->published_at || $restaurant->suspended_at) {
            throw ValidationException::withMessages(['restaurant' => ['Restaurant is unavailable.']]);
        }
        if (! $restaurant->accepting_orders) {
            throw ValidationException::withMessages(['restaurant' => ['Restaurant is not accepting orders.']]);
        }

        return $restaurant;
    }

    /** @param  array<int, string>  $optionPublicIds */
    private function syncModifiers(CartItem $line, MenuItem $item, array $optionPublicIds): void
    {
        $groups = $item->modifierGroups()->with('options')->get();
        $selected = ModifierOption::query()
            ->where('restaurant_id', $item->restaurant_id)
            ->whereIn('public_id', $optionPublicIds)
            ->get();

        foreach ($groups as $group) {
            $groupOptions = $selected->where('modifier_group_id', $group->id);
            $count = $groupOptions->count();
            if ($group->is_required && $count < max(1, $group->minimum_selections)) {
                throw ValidationException::withMessages([
                    'modifiers' => ["Required modifier group {$group->name} is incomplete."],
                ]);
            }
            if ($count > $group->maximum_selections) {
                throw ValidationException::withMessages([
                    'modifiers' => ["Too many selections for {$group->name}."],
                ]);
            }
            if ($group->selection_type === 'single' && $count > 1) {
                throw ValidationException::withMessages([
                    'modifiers' => ["Only one option allowed for {$group->name}."],
                ]);
            }
        }

        foreach ($selected as $option) {
            if (! $option->is_active || ! $option->is_available) {
                throw ValidationException::withMessages(['modifiers' => ['Modifier option unavailable.']]);
            }
            CartItemModifier::query()->create([
                'cart_item_id' => $line->id,
                'modifier_group_id' => $option->modifier_group_id,
                'modifier_option_id' => $option->id,
                'price_adjustment_snapshot_cents' => $option->price_adjustment_cents,
            ]);
        }
    }

    private function validateItem(MenuItem $item, array $input): void
    {
        if (! $item->is_active || ! $item->is_available) {
            throw ValidationException::withMessages(['item' => ['Item is unavailable.']]);
        }
        if (! empty($input['client_price_cents'])) {
            // ignore client price intentionally
        }
    }

    private function serializeCart(Cart $cart): array
    {
        return [
            'public_id' => $cart->public_id,
            'version' => $cart->version,
            'currency' => $cart->currency,
            'restaurant' => [
                'slug' => $cart->restaurant->slug,
                'trading_name' => $cart->restaurant->trading_name,
                'minimum_order_cents' => $cart->restaurant->minimum_order_cents,
            ],
            'items' => $cart->items->map(fn (CartItem $line) => [
                'public_id' => $line->public_id,
                'quantity' => $line->quantity,
                'special_instructions' => $line->special_instructions,
                'menu_item_public_id' => $line->menuItem?->public_id,
                'variant_public_id' => $line->variant?->public_id,
                'name' => $line->menuItem?->name,
                'unit_price_snapshot_cents' => $line->unit_price_snapshot_cents,
                'modifiers' => $line->modifiers->map(fn ($m) => [
                    'modifier_option_id' => $m->modifier_option_id,
                    'price_adjustment_snapshot_cents' => $m->price_adjustment_snapshot_cents,
                ]),
            ])->values(),
        ];
    }
}
