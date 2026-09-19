<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class CancelSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'in:too_expensive,not_using,missing_feature,other'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
