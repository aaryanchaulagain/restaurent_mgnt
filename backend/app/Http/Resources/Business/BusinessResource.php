<?php

namespace App\Http\Resources\Business;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Business */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'business_type' => $this->business_type,
            'ownership_type' => $this->ownership_type,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'description' => $this->description,
            'branches_count' => $this->when(isset($this->branches_count), $this->branches_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
