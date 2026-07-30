<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roles = $this->relationLoaded('roles')
            ? $this->roles
            : $this->roles()->with('permissions')->get();

        $permissions = $roles->flatMap->permissions->pluck('slug')->unique()->values();

        $restaurantAssignments = $this->relationLoaded('restaurantUsers')
            ? $this->restaurantUsers->where('status', 'active')->values()
            : $this->restaurantUsers()->where('status', 'active')->with(['restaurant', 'role'])->get();

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at' => $this->last_login_at,
            'roles' => $roles->pluck('slug')->values(),
            'permissions' => $permissions,
            'mfa_enabled' => (bool) $this->mfaMethod?->is_confirmed,
            'restaurants' => $restaurantAssignments->map(fn ($assignment) => [
                'id' => $assignment->restaurant_id,
                'public_id' => $assignment->restaurant?->public_id,
                'name' => $assignment->restaurant?->trading_name,
                'slug' => $assignment->restaurant?->slug,
                'role' => $assignment->role?->slug,
                'status' => $assignment->status,
            ])->values(),
            'primary_restaurant_id' => $this->primaryRestaurantId(),
            'primary_restaurant_public_id' => $this->primaryRestaurantPublicId(),
        ];
    }
}
