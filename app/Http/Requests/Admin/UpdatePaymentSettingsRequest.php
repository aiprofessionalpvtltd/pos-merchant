<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit-setting') ?? false;
    }

    public function rules(): array
    {
        $amount = ['required', 'integer', 'min:0', 'max:100000000'];

        return [
            'fees.registration.base' => $amount,
            'fees.registration.fee' => $amount,
            'fees.verification.base' => $amount,
            'fees.verification.fee' => $amount,
        ];
    }

    public function attributes(): array
    {
        return [
            'fees.registration.base' => 'registration base price',
            'fees.registration.fee' => 'registration EXELO fee',
            'fees.verification.base' => 'verification base price',
            'fees.verification.fee' => 'verification EXELO fee',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Accept "92,000" as typed in the form.
        $fees = $this->input('fees', []);

        array_walk_recursive($fees, function (&$value) {
            $value = is_string($value) ? str_replace([',', ' '], '', $value) : $value;
        });

        $this->merge(['fees' => $fees]);
    }
}
