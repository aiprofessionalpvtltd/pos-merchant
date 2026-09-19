<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'rail' => ['nullable', 'in:zaad,edahab,cash,card'],
            'wallet_number' => ['nullable', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
