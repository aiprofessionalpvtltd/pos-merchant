<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
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
            'name' => $this->name,
            'address' => $this->address,
            'country' => $this->country,
            'city' => $this->city,
            'state' => $this->state,
            'phone_number' => $this->phone_number,
            'approved' => $this->approved == 0 ? 'Not Approved' : 'Approved',
            'user_id' => $this->user_id,
            'merchant_id' => $this->merchant_id,
            'iccid_number' => $this->iccid_number,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

    }
}
