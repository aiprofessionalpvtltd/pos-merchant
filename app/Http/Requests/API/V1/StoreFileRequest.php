<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'purpose' => ['required', 'in:product_image,signature,merchant_logo'],
            'client_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
