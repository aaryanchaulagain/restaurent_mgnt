<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantServiceArea;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RestaurantServiceAreaController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $areas = RestaurantServiceArea::query()->where('restaurant_id', $restaurantId)->get();

        return ApiResponse::success(['service_areas' => $areas]);
    }

    public function store(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(config('restaurant.service_area_types'))],
            'postcode' => ['nullable', 'string', 'max:12'],
            'radius_km' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_cents' => ['nullable', 'integer', 'min:0'],
            'delivery_fee_cents' => ['nullable', 'integer', 'min:0'],
            'free_delivery_threshold_cents' => ['nullable', 'integer', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $area = RestaurantServiceArea::query()->create(array_merge($data, ['restaurant_id' => $restaurantId]));
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('restaurant.service_area_created', $user, $area, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(['service_area' => $area], status: 201);
    }

    public function update(Request $request, int $id)
    {
        $restaurantId = RestaurantContext::id($request);
        $area = RestaurantServiceArea::query()->where('restaurant_id', $restaurantId)->findOrFail($id);
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(config('restaurant.service_area_types'))],
            'postcode' => ['nullable', 'string', 'max:12'],
            'radius_km' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_cents' => ['nullable', 'integer', 'min:0'],
            'delivery_fee_cents' => ['nullable', 'integer', 'min:0'],
            'free_delivery_threshold_cents' => ['nullable', 'integer', 'min:0'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $area->update($data);
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('restaurant.service_area_updated', $user, $area, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(['service_area' => $area->fresh()]);
    }

    public function destroy(Request $request, int $id)
    {
        $restaurantId = RestaurantContext::id($request);
        $area = RestaurantServiceArea::query()->where('restaurant_id', $restaurantId)->findOrFail($id);
        $area->delete();
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('restaurant.service_area_deleted', $user, null, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(message: 'Deleted.');
    }
}
