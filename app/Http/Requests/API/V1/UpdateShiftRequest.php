<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_time' => ['nullable', 'date', 'required_without:end_time'],
            'end_time' => ['nullable', 'date', 'required_without:start_time'],
            'reason' => ['required', 'string', 'max:200'],
        ];
    }
}
