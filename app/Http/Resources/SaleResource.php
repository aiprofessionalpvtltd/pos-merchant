<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'is_completed' => $this->is_completed,
            'is_successful' => $this->is_successful,

            'total_amount_after_conversion' => round($this->total_amount_after_conversion, 2),
            'amount_to_merchant' => round($this->amount_to_merchant, 2),
            'conversion_fee_amount' => round($this->conversion_fee_amount, 2),
            'transaction_fee_amount' => round($this->transaction_fee_amount, 2),
            'total_fee_charge_to_customer' => round($this->total_fee_charge_to_customer, 2),
            'amount_sent_to_exelo' => round($this->amount_sent_to_exelo, 2),
            'total_amount_charge_to_customer' => round($this->total_amount_charge_to_customer, 2),
            'conversion_rate' => round($this->conversion_rate, 2),
            'currency' => round($this->currency, 2),

        ];
    }
}
