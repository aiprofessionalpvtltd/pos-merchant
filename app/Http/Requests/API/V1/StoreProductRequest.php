<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'max:64'],
            'product_name' => ['required', 'string', 'max:255'],
            'bar_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price' => ['required', 'array'],
            'price.amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'price.currency' => ['required', 'in:USD,SLSH'],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'type' => ['required', 'in:shop,stock'],
            'limits' => ['sometimes', 'array'],
            'limits.stock_limit' => ['sometimes', 'integer', 'min:0'],
            'limits.alarm_limit' => ['sometimes', 'integer', 'min:0'],
            'image_file_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'created_at' => ['sometimes', 'date'],
            'idempotency_key' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
