<?php

namespace App\Services\Restaurant;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\Restaurant\RestaurantActivatedNotification;
use App\Services\Auth\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantActivationService
{
    public function __construct(
        private readonly RestaurantSetupChecklistService $checklist,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function activate(Restaurant $restaurant, User $actor, Request $request): Restaurant
    {
        return DB::transaction(function () use ($restaurant, $actor, $request) {
            $locked = Restaurant::query()->whereKey($restaurant->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RestaurantStatus::Active && $locked->published_at) {
                return $locked;
            }

            if ($locked->suspended_at || $locked->status === RestaurantStatus::Disabled) {
                throw ValidationException::withMessages([
                    'status' => ['This restaurant cannot be activated.'],
                ]);
            }

            $evaluation = $this->checklist->evaluate($locked);
            if (! $evaluation['can_activate'] && $evaluation['missing'] !== []) {
                throw ValidationException::withMessages([
                    'checklist' => ['Setup is incomplete.'],
                    'missing' => $evaluation['missing'],
                ]);
            }

            $locked->forceFill([
                'status' => RestaurantStatus::Active,
                'published_at' => now(),
                'accepting_orders' => true,
                'temporarily_closed_reason' => null,
                'temporarily_closed_until' => null,
            ])->save();

            $this->auditLogger->log('restaurant.activated', $actor, $locked, restaurantId: $locked->id, request: $request);
            $actor->notify(new RestaurantActivatedNotification($locked));

            return $locked->fresh();
        });
    }

    public function temporaryClose(
        Restaurant $restaurant,
        User $actor,
        ?string $reason,
        ?Carbon $until,
        Request $request,
    ): Restaurant {
        $restaurant->forceFill([
            'accepting_orders' => false,
            'temporarily_closed_reason' => $reason,
            'temporarily_closed_until' => $until,
            'status' => RestaurantStatus::TemporarilyClosed,
        ])->save();

        $this->auditLogger->log('restaurant.temporarily_closed', $actor, $restaurant, restaurantId: $restaurant->id, request: $request);

        return $restaurant->fresh();
    }

    public function reopen(Restaurant $restaurant, User $actor, Request $request): Restaurant
    {
        $status = $restaurant->published_at
            ? RestaurantStatus::Active
            : RestaurantStatus::PendingSetup;

        $restaurant->forceFill([
            'accepting_orders' => true,
            'temporarily_closed_reason' => null,
            'temporarily_closed_until' => null,
            'status' => $status,
        ])->save();

        $this->auditLogger->log('restaurant.reopened', $actor, $restaurant, restaurantId: $restaurant->id, request: $request);

        return $restaurant->fresh();
    }
}
