<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:50'],
            'last_name' => ['sometimes', 'string', 'max:50'],
            'dob' => ['sometimes', 'date_format:Y-m-d', 'before:today'],
            'role' => ['sometimes', 'string', 'max:50'],
            'salary' => ['sometimes', 'nullable', 'array'],
            'salary.amount' => ['required_with:salary', 'integer', 'min:0'],
            'salary.currency' => ['required_with:salary', 'in:USD,SLSH'],
            'salary_period' => ['sometimes', 'in:hourly,daily,monthly'],
            'permission_keys' => ['sometimes', 'array', 'min:1'],
            'permission_keys.*' => ['string', 'max:50'],
        ];
    }
}
