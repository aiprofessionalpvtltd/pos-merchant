<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return true;
    }

    /**
     * The shop being edited; unique rules ignore its own values.
     */
    protected function merchantId(): ?int
    {
        return $this->user()->actingMerchant()?->id;
    }

    public function rules(): array
    {
        $merchantId = $this->merchantId();

        return [
            'business_name' => ['sometimes', 'string', 'max:100'],
            'first_name' => ['sometimes', 'string', 'max:50'],
            'last_name' => ['sometimes', 'string', 'max:50'],
            'email' => [
                'sometimes', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($this->user()->id),
                Rule::unique('shops', 'email')->ignore($merchantId)->whereNull('deleted_at'),
            ],
            'state' => ['sometimes', Rule::in(collect(config('exelo.states'))->pluck('code')->all())],
            'city' => ['sometimes', 'string', 'max:100'],
            'merchant_code' => ['sometimes', 'string', 'max:50', Rule::unique('shops', 'merchant_code')->ignore($merchantId)->whereNull('deleted_at')],
            'other_merchant_code' => ['sometimes', 'string', 'max:50', Rule::unique('shops', 'other_merchant_code')->ignore($merchantId)->whereNull('deleted_at')],
            'logo_file_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'state.in' => 'Choose a state',
            'merchant_code.unique' => 'This code is already registered',
            'other_merchant_code.unique' => 'This code is already registered',
            'email.unique' => 'This email is already used by another account',
        ];
    }
}
