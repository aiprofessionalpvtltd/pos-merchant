<?php

namespace App\Http\Requests\API\V1;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'vat_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'vat_inclusive' => ['sometimes', 'boolean'],
            'exchange_rate' => ['sometimes', 'integer', 'min:1'],
            'timezone' => ['sometimes', 'timezone:all'],
            'language' => ['sometimes', 'in:en,so'],
            'receipt' => ['sometimes', 'array'],
            'receipt.footer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'receipt.show_logo' => ['sometimes', 'boolean'],
            'receipt.print_automatically' => ['sometimes', 'boolean'],
            'register' => ['sometimes', 'array'],
            'register.allow_price_override' => ['sometimes', 'boolean'],
            'register.require_customer_on_hold' => ['sometimes', 'boolean'],
            'register.scan_sound' => ['sometimes', 'boolean'],
            'alerts' => ['sometimes', 'array'],
            'alerts.default_alarm_limit' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'alerts.default_stock_limit' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
