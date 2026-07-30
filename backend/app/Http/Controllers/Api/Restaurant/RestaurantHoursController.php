<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantSpecialHour;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Restaurant\RestaurantHoursValidator;
use App\Services\Restaurant\RestaurantOpenStatusService;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RestaurantHoursController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RestaurantHoursValidator $hoursValidator,
        private readonly RestaurantOpenStatusService $openStatus,
    ) {}

    public function index(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $hours = RestaurantOpeningHour::query()->where('restaurant_id', $restaurantId)->orderBy('day_of_week')->get();

        return ApiResponse::success(['hours' => $hours]);
    }

    public function preview(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $restaurant = Restaurant::query()->findOrFail($restaurantId);

        return ApiResponse::success([
            'timezone' => $restaurant->timezone ?: config('restaurant.default_timezone'),
            'is_open' => $this->openStatus->isOpenNow($restaurant),
            'is_open_pickup' => $this->openStatus->isOpenNow($restaurant, 'pickup'),
            'is_open_delivery' => $this->openStatus->isOpenNow($restaurant, 'restaurant_delivery'),
            'next_opening_time' => $this->openStatus->nextOpeningTime($restaurant),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'hours' => ['required', 'array'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.service_type' => ['nullable', 'string', Rule::in(['all', 'pickup', 'restaurant_delivery'])],
        ]);

        $this->hoursValidator->validateRegularHours($data['hours']);

        $restaurantId = RestaurantContext::id($request);
        DB::transaction(function () use ($restaurantId, $data, $request) {
            RestaurantOpeningHour::query()->where('restaurant_id', $restaurantId)->delete();
            foreach ($data['hours'] as $row) {
                if ($row['is_closed']) {
                    RestaurantOpeningHour::query()->create([
                        'restaurant_id' => $restaurantId,
                        'day_of_week' => $row['day_of_week'],
                        'is_closed' => true,
                        'service_type' => $row['service_type'] ?? 'all',
                    ]);

                    continue;
                }
                RestaurantOpeningHour::query()->create([
                    'restaurant_id' => $restaurantId,
                    'day_of_week' => $row['day_of_week'],
                    'opens_at' => $row['opens_at'],
                    'closes_at' => $row['closes_at'],
                    'is_closed' => false,
                    'service_type' => $row['service_type'] ?? 'all',
                ]);
            }
            /** @var User $user */
            $user = $request->user();
            $this->auditLogger->log('restaurant.hours_updated', $user, null, restaurantId: $restaurantId, request: $request);
        });

        return $this->index($request);
    }

    public function listSpecial(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $items = RestaurantSpecialHour::query()->where('restaurant_id', $restaurantId)->orderBy('date')->get();

        return ApiResponse::success(['special_hours' => $items]);
    }

    public function storeSpecial(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'is_closed' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $existing = RestaurantSpecialHour::query()->where('restaurant_id', $restaurantId)->get()->all();
        $this->hoursValidator->validateSpecialHour($data, $existing);
        $row = RestaurantSpecialHour::query()->create(array_merge($data, ['restaurant_id' => $restaurantId]));
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('restaurant.special_hours_created', $user, $row, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(['special_hour' => $row], status: 201);
    }

    public function updateSpecial(Request $request, int $id)
    {
        $restaurantId = RestaurantContext::id($request);
        $row = RestaurantSpecialHour::query()->where('restaurant_id', $restaurantId)->findOrFail($id);
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'is_closed' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $row->update($data);
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('restaurant.special_hours_updated', $user, $row, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(['special_hour' => $row->fresh()]);
    }

    public function deleteSpecial(Request $request, int $id)
    {
        $restaurantId = RestaurantContext::id($request);
        $row = RestaurantSpecialHour::query()->where('restaurant_id', $restaurantId)->findOrFail($id);
        $row->delete();
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('restaurant.special_hours_deleted', $user, null, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(message: 'Deleted.');
    }
}
