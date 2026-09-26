<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            'dob' => ['required', 'date_format:Y-m-d', 'before:today'],
            'role' => ['required', 'string', 'max:50'],
            'salary' => ['nullable', 'array'],
            'salary.amount' => ['required_with:salary', 'integer', 'min:0'],
            'salary.currency' => ['required_with:salary', 'in:USD,SLSH'],
            'salary_period' => ['nullable', 'in:hourly,daily,monthly'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/', 'confirmed'],
            'permission_keys' => ['required', 'array', 'min:1'],
            'permission_keys.*' => ['string', 'max:50'],
            // Owners may add staff to any of their shops; default is the session's current shop.
            'shop_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
