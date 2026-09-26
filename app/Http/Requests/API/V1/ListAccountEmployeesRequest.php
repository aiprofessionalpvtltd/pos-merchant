<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /account/employees: the staff of every shop the merchant owns.
 */
class ListAccountEmployeesRequest extends FormRequest
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
            'shop_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:active,inactive'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
