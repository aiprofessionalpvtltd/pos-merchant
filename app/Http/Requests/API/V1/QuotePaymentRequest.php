<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class QuotePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'array'],
            'amount.amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'amount.currency' => ['required', 'in:USD,SLSH'],
            'rail' => ['required', 'in:zaad,edahab,cash,card,nfc'],
            'purpose' => ['required', 'in:pos_sale,order_settlement'],
        ];
    }
}
