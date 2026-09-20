<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,complete,cancelled'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
