<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        if ($user->isSuperAdmin()) {
            $header = $request->header('X-Restaurant-Id');
            if (! $header) {
                return ApiResponse::error('Restaurant context required. Send X-Restaurant-Id header.', 403, code: 'RESTAURANT_CONTEXT_REQUIRED');
            }

            $restaurant = Restaurant::query()->where('public_id', $header)->first();
            if (! $restaurant) {
                return ApiResponse::error('Restaurant not found.', 404, code: 'RESTAURANT_NOT_FOUND');
            }

            $request->attributes->set('restaurant_id', $restaurant->id);
            $request->attributes->set('restaurant_public_id', $restaurant->public_id);

            return $next($request);
        }

        $restaurantId = $user->primaryRestaurantId();
        if (! $restaurantId) {
            return ApiResponse::error('No active restaurant assignment found.', 403);
        }

        $active = RestaurantUser::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'active')
            ->exists();

        if (! $active) {
            return ApiResponse::error('Restaurant access has been revoked.', 403);
        }

        $request->attributes->set('restaurant_id', $restaurantId);

        return $next($request);
    }
}
