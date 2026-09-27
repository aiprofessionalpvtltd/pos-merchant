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
            // Legacy field name: the shop id (products.shop_id), not the real merchant id.
            'merchant_id' => $this->shop_id,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'price' => $this->price,
            'price_in_sls' => $this->price_sls,
            'exchange_rate' => $this->exchange_rate,
            'vat' => convertVATPercentagetoDecimal($this->vat),
            'total_price' => $this->total_price,
            'total_price_in_sls' => convertUSDToShilling($this->total_price),
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
