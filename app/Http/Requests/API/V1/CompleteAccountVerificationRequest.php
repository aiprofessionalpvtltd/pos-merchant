<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /account/verification/complete: the merchant's own phone, verified by a fee paid from it.
 */
class CompleteAccountVerificationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'string', 'max:100'],
        ];
    }
}
