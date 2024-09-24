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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dob' => $this->dob,
            'location' => $this->location,
            'business_name' => $this->business_name,
            'merchant_code' => $this->merchant_code,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'is_approved' => $this->is_approved == 0 ? 'Not Approved' : 'Approved',
            'confirmation_status' => $this->confirmation_status == true ? 'Confirm' : 'Pending',
            'user_id' => $this->user_id,
            'otp' => $this->otp,
            'otp_expires_at' => $this->otp_expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Ensure that currentSubscription is only loaded if it exists
            'currentSubscription' => new MerchantSubscriptionResource($this->whenLoaded('currentSubscription')),
        ];
    }

}
