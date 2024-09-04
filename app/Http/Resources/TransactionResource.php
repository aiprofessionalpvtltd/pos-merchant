<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction_amount' => $this->transaction_amount,
            'transaction_status' => $this->transaction_status,
            'transaction_message' => $this->transaction_message,
            'phone_number' => $this->phone_number,
            'transaction_id' => $this->transaction_id,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
        ];
    }
}
