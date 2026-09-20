<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class HoldCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:shop,stock'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:100'],
            'customer.mobile_number' => ['required', 'string', 'max:20'],
            'signature_file_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
