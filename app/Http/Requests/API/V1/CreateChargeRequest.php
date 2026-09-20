<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rail' => ['required', 'in:zaad,edahab,cash,card,nfc'],
            'purpose' => ['required', 'in:pos_sale,order_settlement'],
            'amount' => ['required', 'array'],
            'amount.amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'amount.currency' => ['required', 'in:USD'],
            'quote_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer' => ['sometimes', 'array'],
            'customer.wallet_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer.mobile_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'cart_id' => ['required_if:purpose,pos_sale', 'nullable', 'integer'],
            'cart_version' => ['sometimes', 'integer'],
            'order_id' => ['required_if:purpose,order_settlement', 'nullable', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
