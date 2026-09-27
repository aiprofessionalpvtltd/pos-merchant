<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            // Legacy field name: the shop id (categories.shop_id), not the real merchant id.
            'merchant_id' => $this->shop_id,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
        ];
    }
}
