<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\RestaurantProfileResource;
use App\Models\Cuisine;
use App\Models\RestaurantAddress;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Restaurant\RestaurantActivationService;
use App\Services\Restaurant\RestaurantSetupChecklistService;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RestaurantProfileController extends Controller
{
    public function __construct(
        private readonly RestaurantSetupChecklistService $checklist,
        private readonly RestaurantActivationService $activation,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function show(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request)->load('cuisines');

        return ApiResponse::success([
            'profile' => new RestaurantProfileResource($restaurant),
        ]);
    }

    public function update(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);
        $data = $request->validate([
            'trading_name' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'business_email' => ['sometimes', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:32'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('restaurants', 'slug')->ignore($restaurant->id)],
            'price_level' => ['nullable', Rule::in(config('restaurant.price_levels'))],
            'timezone' => ['sometimes', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'minimum_order_cents' => ['nullable', 'integer', 'min:0'],
            'average_preparation_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'restaurant_delivery_enabled' => ['sometimes', 'boolean'],
            'third_party_delivery_enabled' => ['sometimes', 'boolean'],
            'dine_in_enabled' => ['sometimes', 'boolean'],
            'primary_cuisine_id' => ['nullable', 'integer', 'exists:cuisines,id'],
            'cuisine_ids' => ['nullable', 'array'],
            'cuisine_ids.*' => ['integer', 'exists:cuisines,id'],
            'primary_address' => ['nullable', 'array'],
            'primary_address.address_line_1' => ['required_with:primary_address', 'string', 'max:255'],
            'primary_address.address_line_2' => ['nullable', 'string', 'max:255'],
            'primary_address.suburb' => ['required_with:primary_address', 'string', 'max:120'],
            'primary_address.state' => ['required_with:primary_address', 'string', 'max:80'],
            'primary_address.postcode' => ['required_with:primary_address', 'string', 'max:12'],
            'primary_address.country' => ['nullable', 'string', 'max:80'],
            'primary_address.latitude' => ['nullable', 'numeric'],
            'primary_address.longitude' => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($restaurant, $data, $request) {
            $old = $restaurant->only(array_keys($data));
            if (isset($data['trading_name']) && ! isset($data['slug']) && ! $restaurant->slug) {
                $data['slug'] = Str::slug($data['trading_name']);
            }

            $restaurant->fill(collect($data)->except(['cuisine_ids', 'primary_address'])->all())->save();

            if (array_key_exists('cuisine_ids', $data)) {
                $this->syncCuisines($restaurant, $data['cuisine_ids'] ?? [], $data['primary_cuisine_id'] ?? null);
            } elseif (array_key_exists('primary_cuisine_id', $data) && $data['primary_cuisine_id']) {
                $this->syncCuisines($restaurant, [$data['primary_cuisine_id']], $data['primary_cuisine_id']);
            }

            if (isset($data['primary_address'])) {
                RestaurantAddress::query()->updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'is_primary' => true],
                    array_merge($data['primary_address'], [
                        'restaurant_id' => $restaurant->id,
                        'is_primary' => true,
                        'address_type' => 'primary',
                        'country' => $data['primary_address']['country'] ?? 'AU',
                    ]),
                );
            }

            /** @var User $user */
            $user = $request->user();
            $this->auditLogger->log(
                'restaurant.profile_updated',
                $user,
                $restaurant,
                oldValues: $old,
                newValues: collect($data)->except(['primary_address'])->all(),
                restaurantId: $restaurant->id,
                request: $request,
            );
        });

        return ApiResponse::success([
            'profile' => new RestaurantProfileResource($restaurant->fresh()->load('cuisines')),
        ]);
    }

    public function checklist(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);

        return ApiResponse::success($this->checklist->evaluate($restaurant));
    }

    public function activate(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);
        /** @var User $user */
        $user = $request->user();
        $updated = $this->activation->activate($restaurant, $user, $request);

        return ApiResponse::success([
            'profile' => new RestaurantProfileResource($updated->load('cuisines')),
            'checklist' => $this->checklist->evaluate($updated),
        ]);
    }

    public function temporaryClose(Request $request)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'until' => ['nullable', 'date'],
        ]);
        $restaurant = RestaurantContext::restaurant($request);
        /** @var User $user */
        $user = $request->user();
        $until = isset($data['until']) ? Carbon::parse($data['until']) : null;
        $updated = $this->activation->temporaryClose($restaurant, $user, $data['reason'] ?? null, $until, $request);

        return ApiResponse::success(['profile' => new RestaurantProfileResource($updated)]);
    }

    public function reopen(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);
        /** @var User $user */
        $user = $request->user();
        $updated = $this->activation->reopen($restaurant, $user, $request);

        return ApiResponse::success(['profile' => new RestaurantProfileResource($updated)]);
    }

    private function syncCuisines($restaurant, array $cuisineIds, ?int $primaryId): void
    {
        $activeIds = Cuisine::query()->where('is_active', true)->whereIn('id', $cuisineIds)->pluck('id')->all();
        $sync = [];
        foreach ($activeIds as $id) {
            $sync[$id] = ['is_primary' => $primaryId ? $id === (int) $primaryId : false];
        }
        if ($primaryId && in_array((int) $primaryId, $activeIds, true)) {
            foreach ($sync as $id => $_) {
                $sync[$id]['is_primary'] = (int) $id === (int) $primaryId;
            }
            $restaurant->primary_cuisine_id = $primaryId;
            $restaurant->save();
        }
        $restaurant->cuisines()->sync($sync);
    }
}
