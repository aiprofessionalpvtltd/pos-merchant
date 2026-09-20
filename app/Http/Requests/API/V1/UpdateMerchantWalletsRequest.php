<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use App\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantWalletsRequest extends FormRequest
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
            'wallets' => ['required', 'array', 'min:1'],
            'wallets.*.rail' => ['required', 'distinct', Rule::in(Merchant::RAILS)],
            'wallets.*.number' => ['present', 'nullable', 'string', 'regex:/^\+?[0-9\s-]{7,15}$/'],
            'default_rail' => ['sometimes', 'nullable', Rule::in(Merchant::RAILS)],
        ];
    }
}
