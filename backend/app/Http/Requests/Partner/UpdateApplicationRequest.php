<?php

namespace App\Http\Requests\Partner;

use App\Support\Abn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('abn')) {
            $this->merge(['abn' => Abn::normalize($this->input('abn'))]);
        }
    }

    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'integer', 'min:1'],
            'legal_business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_type' => ['sometimes', 'nullable', Rule::in(config('partner.business_types'))],
            'abn' => ['sometimes', 'nullable', 'string', 'size:11'],
            'business_registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'primary_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary_contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'primary_contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cuisine_summary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_type' => ['sometimes', 'nullable', Rule::in(config('partner.service_types'))],
            'expected_monthly_orders' => ['sometimes', 'nullable', 'string', 'max:40'],
            'current_delivery_method' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'referral_source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'array'],
            'address.address_type' => ['sometimes', Rule::in(['registered', 'physical', 'billing'])],
            'address.address_line_1' => ['required_with:address', 'string', 'max:255'],
            'address.address_line_2' => ['nullable', 'string', 'max:255'],
            'address.suburb' => ['required_with:address', 'string', 'max:120'],
            'address.state' => ['required_with:address', Rule::in(array_keys(config('partner.australian_states')))],
            'address.postcode' => ['required_with:address', 'regex:/^\d{4}$/'],
            'address.country' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('abn') && ! Abn::isValid($this->input('abn'))) {
                $validator->errors()->add('abn', 'ABN checksum is invalid.');
            }
        });
    }
}
