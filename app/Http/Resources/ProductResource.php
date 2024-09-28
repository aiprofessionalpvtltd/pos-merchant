<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
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
            'product_name' => $this->product_name,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')), // Assuming you have a CategoryResource
            'merchant_id' => $this->merchant_id,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'price' => convertShillingToUSD($this->price),
            'stock_limit' => $this->stock_limit,
            'alarm_limit' => $this->alarm_limit,
            'image' => Storage::url($this->image),
            'bar_code' => $this->bar_code,
            'in_stock_quantity' => $this->in_stock_quantity,
            'in_shop_quantity' => $this->in_shop_quantity,
            'in_transportation_quantity' => $this->in_transportation_quantity,
            'inventories' => ProductInventoryResource::collection($this->whenLoaded('inventories')), // Assuming inventories are loaded
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
