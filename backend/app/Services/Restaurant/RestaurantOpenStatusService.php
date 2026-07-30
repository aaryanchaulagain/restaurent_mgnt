<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantSpecialHour;
use Carbon\Carbon;

class RestaurantOpenStatusService
{
    public function isOpenNow(Restaurant $restaurant, ?string $serviceType = 'all'): bool
    {
        if (! $restaurant->accepting_orders) {
            return false;
        }

        if ($restaurant->temporarily_closed_until && $restaurant->temporarily_closed_until->isFuture()) {
            return false;
        }

        $tz = $restaurant->timezone ?: config('restaurant.default_timezone');
        $now = Carbon::now($tz);
        $today = $now->toDateString();

        $special = RestaurantSpecialHour::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereDate('date', $today)
            ->first();

        if ($special) {
            if ($special->is_closed) {
                return false;
            }

            return $this->timeInRange($now, $special->opens_at, $special->closes_at);
        }

        $day = (int) $now->dayOfWeek;
        $periods = RestaurantOpeningHour::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('day_of_week', $day)
            ->where('is_closed', false)
            ->when($serviceType !== 'all', fn ($q) => $q->whereIn('service_type', [$serviceType, 'all']))
            ->get();

        foreach ($periods as $period) {
            if ($this->timeInRange($now, $period->opens_at, $period->closes_at)) {
                return true;
            }
        }

        return false;
    }

    public function nextOpeningTime(Restaurant $restaurant, ?string $serviceType = 'all'): ?string
    {
        $tz = $restaurant->timezone ?: config('restaurant.default_timezone');
        $cursor = Carbon::now($tz);

        for ($offset = 0; $offset < 8; $offset++) {
            $day = Carbon::now($tz)->addDays($offset);
            $date = $day->toDateString();
            $dow = (int) $day->dayOfWeek;

            $special = RestaurantSpecialHour::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereDate('date', $date)
                ->first();

            if ($special?->is_closed) {
                continue;
            }

            if ($special && $special->opens_at) {
                $open = Carbon::parse($date.' '.$special->opens_at, $tz);
                if ($offset === 0 && $open->isFuture()) {
                    return $open->toIso8601String();
                }
                if ($offset > 0) {
                    return $open->toIso8601String();
                }
            }

            $periods = RestaurantOpeningHour::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('day_of_week', $dow)
                ->where('is_closed', false)
                ->when($serviceType !== 'all', fn ($q) => $q->whereIn('service_type', [$serviceType, 'all']))
                ->orderBy('opens_at')
                ->get();

            foreach ($periods as $period) {
                if (! $period->opens_at) {
                    continue;
                }
                $open = Carbon::parse($date.' '.$period->opens_at, $tz);
                if ($offset === 0 && $open->isFuture()) {
                    return $open->toIso8601String();
                }
                if ($offset > 0) {
                    return $open->toIso8601String();
                }
            }
        }

        return null;
    }

    private function timeInRange(Carbon $now, ?string $opens, ?string $closes): bool
    {
        if (! $opens || ! $closes) {
            return false;
        }

        $open = Carbon::parse($now->toDateString().' '.$opens, $now->timezone);
        $close = Carbon::parse($now->toDateString().' '.$closes, $now->timezone);

        if ($close->lessThanOrEqualTo($open)) {
            $close->addDay();
        }

        return $now->betweenIncluded($open, $close);
    }
}
