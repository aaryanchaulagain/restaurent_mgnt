<?php

namespace App\Http\Resources\Public;

use App\Models\Business;
use App\Support\BusinessTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Business */
class PublicBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Business $business */
        $business = $this->resource;

        return [
            'public_id' => $business->public_id,
            'slug' => $business->slug,
            'name' => $business->name,
            'description' => $business->description,
            'business_type' => BusinessTypes::normalize($business->business_type),
            'ownership_type' => $business->ownership_type,
            'status' => $business->status,
        ];
    }
}
