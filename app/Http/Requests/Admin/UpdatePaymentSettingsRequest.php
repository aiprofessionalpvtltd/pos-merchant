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
            'fees.sales.percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'fees.gst.percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    public function attributes(): array
    {
        return [
            'fees.registration.base' => 'registration base price',
            'fees.registration.fee' => 'registration EXELO fee',
            'fees.verification.base' => 'verification base price',
            'fees.verification.fee' => 'verification EXELO fee',
            'fees.sales.percent' => 'EXELO sales fee',
            'fees.gst.percent' => 'GST',
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
