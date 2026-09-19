<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            'wallet_number' => ['required', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            'rail' => ['required', 'in:zaad,edahab'],
            'purpose' => ['required', 'in:registration,verification'],
            'quote_id' => ['required', 'string', 'max:50'],
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
