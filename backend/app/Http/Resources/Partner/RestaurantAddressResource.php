<?php

namespace App\Http\Resources\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RestaurantAddress */
class RestaurantAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'address_type' => $this->address_type,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'suburb' => $this->suburb,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'is_primary' => $this->is_primary,
        ];
    }
}
