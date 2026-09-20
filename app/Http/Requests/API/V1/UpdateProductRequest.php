<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_name' => ['sometimes', 'string', 'max:255'],
            'bar_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price' => ['sometimes', 'array'],
            'price.amount' => ['required_with:price', 'integer', 'min:0', 'max:100000000'],
            'price.currency' => ['required_with:price', 'in:USD,SLSH'],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'limits' => ['sometimes', 'array'],
            'limits.stock_limit' => ['sometimes', 'integer', 'min:0'],
            'limits.alarm_limit' => ['sometimes', 'integer', 'min:0'],
            'image_file_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
