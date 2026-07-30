<?php

namespace App\Http\Controllers\Api\Checkout;

use App\Http\Controllers\Controller;
use App\Services\Checkout\CheckoutQuoteService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutQuoteService $quotes) {}

    public function quote(Request $request)
    {
        $data = $request->validate([
            'fulfilment_type' => ['required', Rule::in(['pickup', 'restaurant_delivery', 'third_party_delivery'])],
            'address' => ['nullable', 'array'],
            'address.postcode' => ['nullable', 'string', 'max:12'],
            'address.address_line_1' => ['nullable', 'string', 'max:255'],
            'address.suburb' => ['nullable', 'string', 'max:120'],
            'address.state' => ['nullable', 'string', 'max:80'],
            'address.latitude' => ['nullable', 'numeric'],
            'address.longitude' => ['nullable', 'numeric'],
            'contact' => ['nullable', 'array'],
            'terms_accepted' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($this->quotes->create($request, $data));
    }
}
