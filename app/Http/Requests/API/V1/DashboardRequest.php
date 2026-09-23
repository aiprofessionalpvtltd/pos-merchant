<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'weeks' => ['sometimes', 'integer', 'min:1', 'max:26'],
            'history_limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
