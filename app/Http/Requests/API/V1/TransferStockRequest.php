<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class TransferStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'from' => ['required', 'in:shop,stock,transportation'],
            'to' => ['required', 'in:shop,stock,transportation', 'different:from'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
