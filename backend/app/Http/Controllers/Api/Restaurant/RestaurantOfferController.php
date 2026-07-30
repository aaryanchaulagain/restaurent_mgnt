<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\OfferTarget;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantOfferController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $offers = Offer::query()->where('restaurant_id', $restaurantId)->with('targets')->orderByDesc('created_at')->get();

        return ApiResponse::success(['offers' => $offers]);
    }

    public function store(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'offer_type' => ['required', Rule::in(config('restaurant.offer_types'))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_cents' => ['nullable', 'integer', 'min:0'],
            'maximum_discount_cents' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'targets' => ['nullable', 'array'],
            'targets.*.target_type' => ['required_with:targets', Rule::in(['restaurant', 'menu', 'category', 'item'])],
            'targets.*.target_id' => ['required_with:targets', 'integer', 'min:1'],
        ]);
        $offer = Offer::query()->create(array_merge(collect($data)->except('targets')->all(), [
            'restaurant_id' => $restaurantId,
            'public_id' => (string) Str::uuid(),
        ]));
        $this->syncTargets($restaurantId, $offer, $data['targets'] ?? []);
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('offer.created', $user, $offer, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(['offer' => $offer], status: 201);
    }

    public function update(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $offer = Offer::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->with('targets')->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'offer_type' => ['sometimes', Rule::in(config('restaurant.offer_types'))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_cents' => ['nullable', 'integer', 'min:0'],
            'maximum_discount_cents' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'targets' => ['nullable', 'array'],
            'targets.*.target_type' => ['required_with:targets', Rule::in(['restaurant', 'menu', 'category', 'item'])],
            'targets.*.target_id' => ['required_with:targets', 'integer', 'min:1'],
        ]);
        $offer->update(collect($data)->except('targets')->all());
        if (array_key_exists('targets', $data)) {
            $this->syncTargets($restaurantId, $offer, $data['targets'] ?? []);
        }
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('offer.updated', $user, $offer, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(['offer' => $offer->fresh()]);
    }

    public function show(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $offer = Offer::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->with('targets')->firstOrFail();

        return ApiResponse::success(['offer' => $offer]);
    }

    public function destroy(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $offer = Offer::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->with('targets')->firstOrFail();
        $offer->delete();
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log('offer.deleted', $user, $offer, restaurantId: $restaurantId, request: $request);

        return ApiResponse::success(message: 'Deleted.');
    }

    /** @param  array<int, array{target_type: string, target_id: int}>  $targets */
    private function syncTargets(int $restaurantId, Offer $offer, array $targets): void
    {
        OfferTarget::query()->where('offer_id', $offer->id)->delete();
        foreach ($targets as $target) {
            $this->assertTargetOwnership($restaurantId, $target['target_type'], (int) $target['target_id']);
            OfferTarget::query()->create([
                'offer_id' => $offer->id,
                'target_type' => $target['target_type'],
                'target_id' => $target['target_id'],
            ]);
        }
    }

    private function assertTargetOwnership(int $restaurantId, string $type, int $targetId): void
    {
        $ok = match ($type) {
            'restaurant' => $targetId === $restaurantId,
            'menu' => Menu::query()->where('restaurant_id', $restaurantId)->where('id', $targetId)->exists(),
            'category' => MenuCategory::query()->where('restaurant_id', $restaurantId)->where('id', $targetId)->exists(),
            'item' => MenuItem::query()->where('restaurant_id', $restaurantId)->where('id', $targetId)->exists(),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['targets' => ['Invalid offer target for this restaurant.']]);
        }
    }
}
