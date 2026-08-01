<?php

namespace App\Services\Branch;

use App\Enums\Partner\RestaurantStatus;
use App\Events\Branch\BranchStatusChanged;
use App\Models\Branch;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\BranchStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BranchStatusService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function change(Branch $branch, string $newStatus, User $actor, ?Request $request = null): Branch
    {
        $old = $branch->status;

        if ($old === $newStatus) {
            return $branch;
        }

        if ($newStatus === BranchStatuses::SUSPENDED && ! $actor->isSuperAdmin()) {
            throw new HttpException(403, 'Only platform super admins may suspend branches.');
        }

        if ($old === BranchStatuses::SUSPENDED && $newStatus !== BranchStatuses::SUSPENDED && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'status' => ['Suspended branches cannot be changed by business staff.'],
            ]);
        }

        if ($branch->status === BranchStatuses::SUSPENDED && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'status' => ['Suspended branches cannot be changed by business staff.'],
            ]);
        }

        if (! $actor->isSuperAdmin() && ! in_array($newStatus, BranchStatuses::ownerAssignable(), true)) {
            throw ValidationException::withMessages(['status' => ['Invalid branch status transition.']]);
        }

        if (! in_array($newStatus, BranchStatuses::all(), true)) {
            throw ValidationException::withMessages(['status' => ['Unsupported branch status.']]);
        }

        return DB::transaction(function () use ($branch, $old, $newStatus, $actor, $request) {
            $branch->forceFill([
                'status' => $newStatus,
                'suspended_at' => $newStatus === BranchStatuses::SUSPENDED ? now() : null,
                'accepting_orders' => $newStatus === BranchStatuses::ACTIVE ? $branch->accepting_orders : false,
            ])->save();

            $this->mirrorRestaurantStatus($branch, $newStatus);

            $this->auditLogger->log(
                'branch.status_changed',
                $actor,
                $branch,
                oldValues: ['status' => $old],
                newValues: ['status' => $newStatus],
                restaurantId: $branch->restaurant_id,
                metadata: [
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'actor_user_id' => $actor->id,
                ],
                request: $request,
            );

            event(new BranchStatusChanged($branch, $old, $newStatus, $actor));

            return $branch->fresh(['restaurant', 'business']);
        });
    }

    public function pause(Branch $branch, User $actor, ?Request $request = null): Branch
    {
        return $this->change($branch, BranchStatuses::PAUSED, $actor, $request);
    }

    public function activate(Branch $branch, User $actor, ?Request $request = null): Branch
    {
        return $this->change($branch, BranchStatuses::ACTIVE, $actor, $request);
    }

    public function deactivate(Branch $branch, User $actor, ?Request $request = null): Branch
    {
        return $this->change($branch, BranchStatuses::INACTIVE, $actor, $request);
    }

    public function suspend(Branch $branch, User $actor, ?Request $request = null): Branch
    {
        return $this->change($branch, BranchStatuses::SUSPENDED, $actor, $request);
    }

    public function unsuspend(Branch $branch, User $actor, ?Request $request = null): Branch
    {
        // Unsuspend returns to paused so owners must explicitly activate.
        return $this->change($branch, BranchStatuses::PAUSED, $actor, $request);
    }

    private function mirrorRestaurantStatus(Branch $branch, string $status): void
    {
        $restaurant = $branch->restaurant;
        if (! $restaurant) {
            return;
        }

        $mapped = match ($status) {
            BranchStatuses::ACTIVE => RestaurantStatus::Active,
            BranchStatuses::PAUSED => RestaurantStatus::TemporarilyClosed,
            BranchStatuses::DRAFT => RestaurantStatus::PendingSetup,
            BranchStatuses::INACTIVE, BranchStatuses::SUSPENDED => RestaurantStatus::Disabled,
            default => RestaurantStatus::PendingSetup,
        };

        $restaurant->forceFill([
            'status' => $mapped,
            'accepting_orders' => $status === BranchStatuses::ACTIVE && $restaurant->accepting_orders,
            'suspended_at' => $status === BranchStatuses::SUSPENDED ? now() : null,
            'published_at' => $status === BranchStatuses::ACTIVE
                ? ($restaurant->published_at ?? now())
                : $restaurant->published_at,
        ])->save();
    }
}
