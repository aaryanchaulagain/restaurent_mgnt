<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\User;
use App\Services\Restaurant\RestaurantMembershipService;
use App\Services\Restaurant\RestaurantProvisionService;
use App\Support\ApiResponse;
use App\Support\VendorTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminRestaurantController extends Controller
{
    public function __construct(
        private readonly RestaurantProvisionService $provisionService,
        private readonly RestaurantMembershipService $membership,
    ) {}

    public function index(Request $request)
    {
        $query = Restaurant::query()
            ->withCount(['restaurantUsers as active_staff_count' => fn ($q) => $q->where('status', 'active')])
            ->with(['commissionAgreements' => fn ($q) => $q->where('status', 'accepted')->latest('id')])
            ->orderByRaw("CASE WHEN ownership_type = 'first_party' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('ownership_type')) {
            $query->where('ownership_type', $request->string('ownership_type'));
        }
        if ($request->filled('vendor_type')) {
            $query->where('vendor_type', $request->string('vendor_type'));
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('trading_name', 'like', $q)
                    ->orWhere('legal_business_name', 'like', $q)
                    ->orWhere('slug', 'like', $q)
                    ->orWhere('business_email', 'like', $q);
            });
        }

        $page = $query->paginate(min(50, (int) $request->input('per_page', 25)));

        return ApiResponse::success([
            'restaurants' => $page->getCollection()->map(fn (Restaurant $r) => $this->listResource($r)),
        ], meta: [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function show(string $publicId)
    {
        $restaurant = Restaurant::query()
            ->where('public_id', $publicId)
            ->with([
                'commissionAgreements' => fn ($q) => $q->latest('id'),
                'restaurantUsers' => fn ($q) => $q->where('status', '!=', 'removed')->with(['user', 'role']),
                'primaryCuisine',
            ])
            ->firstOrFail();

        return ApiResponse::success([
            'restaurant' => $this->detailResource($restaurant),
        ]);
    }

    public function provision(Request $request)
    {
        $data = $request->validate([
            'trading_name' => ['required', 'string', 'max:160'],
            'legal_business_name' => ['required', 'string', 'max:200'],
            'business_email' => ['nullable', 'email', 'max:190'],
            'business_phone' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ownership_type' => ['nullable', Rule::in(['first_party', 'third_party'])],
            'vendor_type' => ['nullable', Rule::in(VendorTypes::all())],
            'activate_now' => ['sometimes', 'boolean'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'owner.first_name' => ['required', 'string', 'max:80'],
            'owner.last_name' => ['required', 'string', 'max:80'],
            'owner.email' => ['required', 'email', 'max:190'],
            'owner.password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()],
            'owner.phone' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $this->provisionService->provision($data, $request->user(), $request);

        return ApiResponse::success([
            'restaurant' => $this->detailResource($result['restaurant']),
            'owner' => [
                'id' => $result['owner']->id,
                'email' => $result['owner']->email,
                'name' => $result['owner']->name,
            ],
            'temporary_password' => $result['temporary_password'],
            'business' => [
                'id' => $result['business']->id,
                'public_id' => $result['business']->public_id,
                'name' => $result['business']->name,
                'slug' => $result['business']->slug,
            ],
            'branch' => [
                'id' => $result['branch']->id,
                'public_id' => $result['branch']->public_id,
                'name' => $result['branch']->name,
                'code' => $result['branch']->code,
            ],
        ], message: 'Partner business and owner provisioned.', status: 201);
    }

    public function update(Request $request, string $publicId)
    {
        $restaurant = Restaurant::query()->where('public_id', $publicId)->firstOrFail();

        $data = $request->validate([
            'trading_name' => ['sometimes', 'string', 'max:160'],
            'legal_business_name' => ['sometimes', 'string', 'max:200'],
            'business_email' => ['nullable', 'email', 'max:190'],
            'business_phone' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:280'],
            'status' => ['sometimes', Rule::in(['pending_setup', 'active', 'temporarily_closed', 'suspended', 'disabled'])],
            'accepting_orders' => ['sometimes', 'boolean'],
            'suspension_reason' => ['nullable', 'string', 'max:500'],
            'temporarily_closed_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $restaurant = $this->provisionService->updateRestaurant($restaurant, $data, $request->user(), $request);

        return ApiResponse::success([
            'restaurant' => $this->detailResource($restaurant->load([
                'commissionAgreements',
                'restaurantUsers' => fn ($q) => $q->where('status', '!=', 'removed')->with(['user', 'role']),
            ])),
        ]);
    }

    public function destroy(Request $request, string $publicId)
    {
        $restaurant = Restaurant::query()->where('public_id', $publicId)->firstOrFail();
        $restaurant = $this->provisionService->softRemove($restaurant, $request->user(), $request);

        return ApiResponse::success([
            'restaurant' => [
                'public_id' => $restaurant->public_id,
                'status' => $restaurant->status?->value ?? $restaurant->status,
                'deleted' => $restaurant->trashed(),
            ],
        ], message: 'Restaurant removed.');
    }

    public function addOwner(Request $request, string $publicId)
    {
        $restaurant = Restaurant::query()->where('public_id', $publicId)->firstOrFail();

        $data = $request->validate([
            'first_name' => ['required_without:user_id', 'string', 'max:80'],
            'last_name' => ['required_without:user_id', 'string', 'max:80'],
            'email' => ['required_without:user_id', 'email', 'max:190'],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['sometimes', Rule::in(['restaurant_owner', 'restaurant_manager'])],
        ]);

        $roleSlug = $data['role'] ?? 'restaurant_owner';
        $temporaryPassword = null;

        if (! empty($data['user_id'])) {
            $user = User::query()->findOrFail($data['user_id']);
        } else {
            $email = strtolower(trim($data['email']));
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                $temporaryPassword = $data['password'] ?? Str::password(12);
                $user = User::query()->create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $email,
                    'phone' => $data['phone'] ?? null,
                    'password' => $temporaryPassword,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
            }
        }

        $membership = $this->membership->assign($restaurant, $user, $roleSlug, $request->user(), $request);

        return ApiResponse::success([
            'member' => $this->memberResource($membership),
            'temporary_password' => $temporaryPassword,
        ], message: 'Owner assigned.', status: 201);
    }

    public function removeOwner(Request $request, string $publicId, int $userId)
    {
        $restaurant = Restaurant::query()->where('public_id', $publicId)->firstOrFail();
        $user = User::query()->findOrFail($userId);
        $this->membership->revoke($restaurant, $user, $request->user(), $request);

        return ApiResponse::success(message: 'Owner access revoked.');
    }

    private function listResource(Restaurant $r): array
    {
        $agreement = $r->commissionAgreements->first();

        return [
            'public_id' => $r->public_id,
            'slug' => $r->slug,
            'trading_name' => $r->trading_name,
            'legal_business_name' => $r->legal_business_name,
            'business_email' => $r->business_email,
            'status' => $r->status?->value ?? $r->status,
            'ownership_type' => $r->ownership_type,
            'vendor_type' => $r->vendor_type ?: VendorTypes::RESTAURANT,
            'accepting_orders' => (bool) $r->accepting_orders,
            'published_at' => $r->published_at,
            'active_staff_count' => (int) ($r->active_staff_count ?? 0),
            'commission_rate' => $agreement?->commission_rate,
        ];
    }

    private function detailResource(Restaurant $r): array
    {
        return array_merge($this->listResource($r), [
            'description' => $r->description,
            'short_description' => $r->short_description,
            'business_phone' => $r->business_phone,
            'timezone' => $r->timezone,
            'currency' => $r->currency,
            'suspended_at' => $r->suspended_at,
            'suspension_reason' => $r->suspension_reason,
            'temporarily_closed_reason' => $r->temporarily_closed_reason,
            'owners' => $r->relationLoaded('restaurantUsers')
                ? $r->restaurantUsers->map(fn (RestaurantUser $m) => $this->memberResource($m))->values()
                : [],
            'commission_agreements' => $r->relationLoaded('commissionAgreements')
                ? $r->commissionAgreements->map(fn ($a) => [
                    'id' => $a->id,
                    'commission_type' => $a->commission_type,
                    'commission_rate' => $a->commission_rate,
                    'status' => $a->status,
                    'effective_from' => $a->effective_from,
                ])->values()
                : [],
        ]);
    }

    private function memberResource(RestaurantUser $m): array
    {
        return [
            'user_id' => $m->user_id,
            'email' => $m->user?->email,
            'name' => $m->user?->name,
            'role' => $m->role?->slug,
            'status' => $m->status,
            'joined_at' => $m->joined_at,
        ];
    }
}
