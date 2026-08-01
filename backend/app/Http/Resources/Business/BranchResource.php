<?php

namespace App\Http\Resources\Business;

use App\Models\Branch;
use App\Support\BranchStatuses;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'business_id' => $this->business_id,
            'business_public_id' => $this->relationLoaded('business') ? $this->business?->public_id : null,
            'business_name' => $this->relationLoaded('business') ? $this->business?->name : null,
            'name' => $this->name,
            'code' => $this->code,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'status_label' => BranchStatuses::label((string) $this->status),
            'timezone' => $this->timezone,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'delivery_radius_km' => $this->delivery_radius_km,
            'minimum_order_amount_cents' => $this->minimum_order_amount_cents,
            'accepting_orders' => (bool) $this->accepting_orders,
            'is_default' => (bool) $this->is_default,
            'is_operational' => BranchStatuses::isOperational((string) $this->status),
            'allows_configuration' => BranchStatuses::allowsConfiguration((string) $this->status),
            'restaurant_public_id' => $this->whenLoaded('restaurant', fn () => $this->restaurant?->public_id),
            'staff_count' => $this->when(isset($this->branch_users_count), $this->branch_users_count),
            'manager_count' => $this->when(isset($this->manager_count), $this->manager_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
