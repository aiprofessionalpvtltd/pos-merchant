<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class SalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['sometimes', 'in:day,week,month,payment_method,employee'],
            'format' => ['sometimes', 'in:json'],
        ];
    }

    public function messages(): array
    {
        return ['format.in' => 'Only format=json is available for now'];
    }
}
