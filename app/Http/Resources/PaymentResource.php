<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'sale_id' => $this->sale_id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'transaction_id' => $this->transaction_id,
            'is_successful' => $this->is_successful,
            'amount_to_merchant' => $this->amount_to_merchant,
            'amount_to_exelo' => $this->amount_to_exelo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
