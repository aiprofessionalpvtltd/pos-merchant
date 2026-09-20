<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:shop,stock,transportation'],
            'category_id' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'string', 'max:100'],
            'updated_since' => ['sometimes', 'date'],
            'include_deleted' => ['sometimes', 'boolean'],
            'in_stock_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
