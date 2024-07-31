<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantSubscriptionResource extends JsonResource
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
            'subscription_plan_id' => $this->subscription_plan_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'transaction_status' => $this->transaction_status,
            'is_canceled' => $this->is_canceled,
            'canceled_at' => $this->canceled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
