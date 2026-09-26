<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /shops: a merchant creates a shop, free, with the shop's own phone number.
 */
class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:100'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            'state' => ['required', Rule::in(array_column(config('exelo.states'), 'code'))],
            'city' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('shops', 'email')->whereNull('deleted_at')],
            'merchant_code' => ['nullable', 'string', 'max:50'],
            'other_merchant_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
