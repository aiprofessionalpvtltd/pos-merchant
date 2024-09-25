<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Calculate total shop quantity from inventories
        $totalShopQuantity = $this->inventories
            ->where('type', 'shop')
            ->sum('quantity');

        return [
            'id' => $this->id,
            'name' => $this->product_name,
            'price' => $this->price,
            'image' => Storage::url($this->image),
            'category' => $this->category->name ?? null, // Assuming `category` relationship exists
            'total_sold' => $this->orderItems->sum('quantity'), // Sum of quantities from orderItems
            'total_shop_quantity' => $totalShopQuantity, // Total quantity from inventories where type is shop
        ];
    }
}
