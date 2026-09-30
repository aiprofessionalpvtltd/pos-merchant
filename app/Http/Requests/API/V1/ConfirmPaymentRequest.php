<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_transaction_id' => ['required', 'string', 'max:80'],
            'confirmation_code' => ['required', 'string', 'max:20'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }
}
