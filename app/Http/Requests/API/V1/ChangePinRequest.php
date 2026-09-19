<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class ChangePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_pin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/', 'confirmed'],
        ];
    }
}
