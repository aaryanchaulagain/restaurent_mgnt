<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerAddressController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $addresses = CustomerAddress::query()
            ->where('customer_id', $user->id)
            ->orderByDesc('is_default')
            ->get();

        return ApiResponse::success(['addresses' => $addresses]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:80'],
            'postcode' => ['required', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'size:2'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            CustomerAddress::query()->where('customer_id', $user->id)->update(['is_default' => false]);
        }

        $address = CustomerAddress::query()->create(array_merge($data, [
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'country' => $data['country'] ?? 'AU',
        ]));

        return ApiResponse::success(['address' => $address], status: 201);
    }

    public function update(Request $request, string $publicId)
    {
        $user = $request->user();
        $address = CustomerAddress::query()
            ->where('customer_id', $user->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'recipient_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line_1' => ['sometimes', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['sometimes', 'string', 'max:120'],
            'state' => ['sometimes', 'string', 'max:80'],
            'postcode' => ['sometimes', 'string', 'max:12'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            CustomerAddress::query()->where('customer_id', $user->id)->update(['is_default' => false]);
        }

        $address->update($data);

        return ApiResponse::success(['address' => $address->fresh()]);
    }

    public function destroy(Request $request, string $publicId)
    {
        $user = $request->user();
        $address = CustomerAddress::query()
            ->where('customer_id', $user->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $address->delete();

        return ApiResponse::success(message: 'Deleted.');
    }
}
