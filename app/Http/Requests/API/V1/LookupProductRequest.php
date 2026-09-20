<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class LookupProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:64'],
            'type' => ['sometimes', 'in:shop,stock'],
        ];
    }
}
