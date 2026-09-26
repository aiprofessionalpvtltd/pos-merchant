<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /account: the merchant's own details (not their shops').
 */
class UpdateMerchantAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->isMerchantAccount()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:50'],
            'last_name' => ['sometimes', 'string', 'max:50'],
            'dob' => ['sometimes', 'date_format:Y-m-d', 'before:today'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191', Rule::unique('merchants', 'email')->ignore($this->user()->merchantAccount?->id)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return ['dob.date_format' => 'Enter a valid date of birth', 'dob.before' => 'Enter a valid date of birth'];
    }
}
