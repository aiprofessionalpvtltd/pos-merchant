<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_order_id' => ['required', 'string', 'max:64'],
            'status' => ['sometimes', 'in:pending'],
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer.mobile_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signature_file_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'created_at' => ['sometimes', 'date'],
            'idempotency_key' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return ['status.in' => 'Create the order as pending, then pay it'];
    }
}
