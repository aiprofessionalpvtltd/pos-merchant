<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'phone_number' => $this->phone_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dob' => $this->dob,
            'location' => $this->location,
            'role' => $this->role,
            'salary' => $this->salary,
            'salary_in_usd' => convertShillingToUSD($this->salary),
            'permissions' => EmployeePermissionResource::collection($this->whenLoaded('permissions')), // Use 'permissions' relation directly
            'user' => new UserResource($this->whenLoaded('user')), // Assuming you have a CategoryResource

        ];
    }
}
