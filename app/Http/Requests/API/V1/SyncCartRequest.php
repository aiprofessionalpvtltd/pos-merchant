<?php

namespace App\Http\Requests\API\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:shop,stock'],
            'client_ticket_id' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'strategy' => ['sometimes', 'in:merge,replace'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.client_line_id' => ['required', 'string', 'max:64', 'distinct'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'max:100000'],
            'lines.*.unit_price' => ['sometimes', 'array'],
            'lines.*.unit_price.amount' => ['required_with:lines.*.unit_price', 'integer', 'min:0', 'max:100000000'],
            'lines.*.unit_price.currency' => ['required_with:lines.*.unit_price', 'in:USD,SLSH'],
        ];
    }
}
