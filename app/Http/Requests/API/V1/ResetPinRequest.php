<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class ResetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/', 'confirmed'],
        ];
    }
}
