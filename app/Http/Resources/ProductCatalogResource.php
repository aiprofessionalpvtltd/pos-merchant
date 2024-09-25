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
        return [
            'id' => $this->id,
            'name' => $this->product_name,
            'price' => $this->price,
            'image' => Storage::url($this->image),
            'category' => $this->category->name ?? null, // Assuming `category` relationship exists
            'total_sold' => $this->orderItems->sum('quantity'), // Sum of quantities from orderItems
        ];
    }
}
