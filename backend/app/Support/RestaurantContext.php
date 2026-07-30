<?php

namespace App\Support;

use App\Models\Restaurant;
use Illuminate\Http\Request;

final class RestaurantContext
{
    public static function id(Request $request): int
    {
        $id = $request->attributes->get('restaurant_id');
        if (! $id && $request->user()) {
            $id = $request->user()->primaryRestaurantId();
        }
        if (! $id) {
            abort(403, 'Restaurant context missing.');
        }

        return (int) $id;
    }

    public static function restaurant(Request $request): Restaurant
    {
        return Restaurant::query()->findOrFail(self::id($request));
    }
}
