<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OrderResource extends JsonResource
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
            'user_id' => $this->user_id,
            'name' => $this->name,
            'mobile_number' => $this->mobile_number,
            'signature' => Storage::url($this->signature),
            'sub_total' => $this->sub_total,
            'vat' => $this->vat,
            'exelo_amount' => $this->exelo_amount,
            'total_price' => $this->total_price,
            'order_type' => $this->order_type,
            'order_status' => $this->order_status,
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
            'quantity' => $this->whenLoaded('items', function () {
                return $this->items->sum('quantity');
            }),

        ];
    }
}
