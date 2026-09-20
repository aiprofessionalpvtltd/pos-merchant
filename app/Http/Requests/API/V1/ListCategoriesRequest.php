<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'with_counts' => ['sometimes', 'boolean'],
            'updated_since' => ['sometimes', 'date'],
        ];
    }
}
