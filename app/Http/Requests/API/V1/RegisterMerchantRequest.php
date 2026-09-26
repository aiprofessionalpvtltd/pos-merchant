<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['required', 'date_format:Y-m-d', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            // Shop fields are deprecated here: send none of them to create the merchant account only,
            // then create its first shop with POST /shops (docs/merchant-onboarding.md).
            'business_name' => ['nullable', 'required_with:state,city', 'string', 'max:255'],
            'state' => ['nullable', 'required_with:business_name', Rule::in(array_column(config('exelo.states'), 'code'))],
            'city' => ['nullable', 'required_with:business_name', 'string', 'max:255'],
            'merchant_code' => ['nullable', 'string', 'max:255'],
            'other_merchant_code' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'state.required' => 'Choose a state',
            'state.in' => 'Choose a state',
            'dob.required' => 'Enter a valid date of birth',
            'dob.date' => 'Enter a valid date of birth',
            'dob.date_format' => 'Enter a valid date of birth',
            'dob.before' => 'Enter a valid date of birth',
        ];
    }
}
