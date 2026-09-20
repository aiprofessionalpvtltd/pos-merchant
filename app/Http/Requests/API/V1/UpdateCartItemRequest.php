<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:shop,stock'],
            'quantity' => ['sometimes', 'integer', 'max:100000'],
            'unit_price' => ['sometimes', 'array'],
            'unit_price.amount' => ['required_with:unit_price', 'integer', 'min:0', 'max:100000000'],
            'unit_price.currency' => ['required_with:unit_price', 'in:USD,SLSH'],
        ];
    }
}
