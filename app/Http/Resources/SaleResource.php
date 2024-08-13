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
            'total_customer_charge' => round($this->total_customer_charge, 2),
            'total_customer_charge_usd' => round($this->total_customer_charge_usd, 2),
            'amount_sent_to_exelo' => round($this->amount_sent_to_exelo, 2),
            'amount_sent_to_exelo_usd' => round($this->amount_sent_to_exelo_usd, 2),
            'merchant_receives' => round($this->merchant_receives, 2),
            'merchant_receives_usd' => round($this->merchant_receives_usd, 2),
            'zaad_fee_from_exelo' => round($this->zaad_fee_from_exelo, 2),
            'zaad_fee_from_exelo_usd' => round($this->zaad_fee_from_exelo_usd, 2),
            'zaad_fee' => round($this->zaad_fee, 2),
            'zaad_fee_usd' => round($this->zaad_fee_usd, 2),
            'conversion_rate' => round($this->conversion_rate, 2),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
