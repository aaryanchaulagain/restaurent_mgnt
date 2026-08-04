<?php

namespace App\Http\Requests\Branch;

use App\Support\BranchStatuses;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(BranchStatuses::ownerAssignable())],
            'timezone' => ['nullable', 'string', 'max:64'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_radius_km' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'minimum_order_amount_cents' => ['nullable', 'integer', 'min:0'],

            'invite_manager' => ['sometimes', 'boolean'],
            'manager_full_name' => ['nullable', 'required_if:invite_manager,true', 'string', 'max:160'],
            'manager_email' => ['nullable', 'required_if:invite_manager,true', 'email', 'max:190'],
            'manager_phone' => ['nullable', 'string', 'max:40'],
            'manager_role' => ['nullable', Rule::in([BusinessRoles::BRANCH_MANAGER])],
        ];
    }
}
