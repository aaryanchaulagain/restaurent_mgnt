<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    public function access(User $user, ?Restaurant $restaurant = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $restaurantId = $restaurant?->id ?? $user->primaryRestaurantId();
        if (! $restaurantId) {
            return false;
        }

        return $user->restaurantUsers()
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'active')
            ->exists();
    }
}
