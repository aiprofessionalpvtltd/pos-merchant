<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /employees/{id}/transfer: move a staff member to another of the owner's shops.
 */
class TransferEmployeeRequest extends FormRequest
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
            'shop_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
