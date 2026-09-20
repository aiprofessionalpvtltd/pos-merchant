<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'in_shop' => ['required_without:in_stock', 'integer', 'min:0', 'max:1000000'],
            'in_stock' => ['required_without:in_shop', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'in:recount,damage,theft,expiry,correction'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
