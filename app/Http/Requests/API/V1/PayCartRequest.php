<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class PayCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:shop,stock'],
            'cart_version' => ['required', 'integer'],
            'rail' => ['required', 'in:cash,zaad,edahab,card,nfc'],
            'quote_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount_tendered' => ['sometimes', 'array'],
            'amount_tendered.amount' => ['required_with:amount_tendered', 'integer', 'min:0', 'max:100000000'],
            'amount_tendered.currency' => ['required_with:amount_tendered', 'in:USD'],
            'customer' => ['sometimes', 'array'],
            'customer.wallet_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer.mobile_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
