<?php

namespace App\Http\Requests\Branch;

use App\Support\BusinessRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBranchInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'full_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['nullable', Rule::in(BusinessRoles::branchLevel())],
        ];
    }
}
