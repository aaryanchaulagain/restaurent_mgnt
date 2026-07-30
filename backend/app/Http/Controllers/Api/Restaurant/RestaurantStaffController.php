<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantUser;
use App\Models\User;
use App\Services\Restaurant\RestaurantMembershipService;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RestaurantStaffController extends Controller
{
    public function __construct(private readonly RestaurantMembershipService $membership) {}

    public function index(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);

        $members = RestaurantUser::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', '!=', 'removed')
            ->with(['user', 'role'])
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'staff' => $members->map(fn (RestaurantUser $m) => [
                'user_id' => $m->user_id,
                'email' => $m->user?->email,
                'name' => $m->user?->name,
                'first_name' => $m->user?->first_name,
                'last_name' => $m->user?->last_name,
                'role' => $m->role?->slug,
                'status' => $m->status,
                'joined_at' => $m->joined_at,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $restaurant = RestaurantContext::restaurant($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(['restaurant_manager', 'restaurant_staff'])],
        ]);

        // Owners cannot escalate someone to owner via staff UI — use admin for owners.
        $email = strtolower(trim($data['email']));
        $temporaryPassword = null;
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
        } elseif ($user->isSuperAdmin()) {
            return ApiResponse::error('Cannot assign restaurant staff roles to a super admin.', 422);
        }

        $membership = $this->membership->assign(
            $restaurant,
            $user,
            $data['role'],
            $request->user(),
            $request,
        );

        return ApiResponse::success([
            'member' => [
                'user_id' => $membership->user_id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $membership->role?->slug ?? $data['role'],
                'status' => $membership->status,
            ],
            'temporary_password' => $temporaryPassword,
        ], message: 'Staff member added.', status: 201);
    }

    public function update(Request $request, int $userId)
    {
        $restaurant = RestaurantContext::restaurant($request);

        $data = $request->validate([
            'role' => ['required', Rule::in(['restaurant_manager', 'restaurant_staff'])],
        ]);

        $user = User::query()->findOrFail($userId);
        $membership = RestaurantUser::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'removed')
            ->firstOrFail();

        // Do not let restaurant owners demote themselves accidentally without another owner —
        // still allow role change for managers/staff only via this endpoint.
        if ($membership->role?->slug === 'restaurant_owner') {
            return ApiResponse::error('Change owner roles from the platform admin panel.', 422);
        }

        $membership = $this->membership->assign(
            $restaurant,
            $user,
            $data['role'],
            $request->user(),
            $request,
        );

        return ApiResponse::success([
            'member' => [
                'user_id' => $membership->user_id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $data['role'],
                'status' => $membership->status,
            ],
        ]);
    }

    public function destroy(Request $request, int $userId)
    {
        $restaurant = RestaurantContext::restaurant($request);
        $user = User::query()->findOrFail($userId);

        $membership = RestaurantUser::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership?->role?->slug === 'restaurant_owner' && ! $request->user()->isSuperAdmin()) {
            return ApiResponse::error('Owners must be removed by a platform admin.', 422);
        }

        if ($user->id === $request->user()->id) {
            return ApiResponse::error('You cannot revoke your own access.', 422);
        }

        $this->membership->revoke($restaurant, $user, $request->user(), $request);

        return ApiResponse::success(message: 'Staff access revoked.');
    }
}
