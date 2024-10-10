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
        $totalShopQuantity = $this->history
            ->where('type', 'shop')
            ->sum('quantity');

        $totalStockQuantity = $this->history
            ->where('type', 'stock')
            ->sum('quantity');

        // Check total sold based on order items
        $totalSold = $this->orderItems->sum('quantity');

        // If both total shop and stock quantities are zero, calculate total sold
        if ($totalShopQuantity == 0 && $totalStockQuantity == 0) {
            // Add your logic here to fetch the total sold within a specific date range if needed
            // Example: Assuming you are passing start_date and end_date as query parameters
            $startDate = request()->query('start_date');
            $endDate = request()->query('end_date');

            // Validate and format the date
            if ($startDate && $endDate) {
                $totalSold = $this->orderItems()
                    ->whereBetween('created_at', [
                        \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay(),
                        \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay(),
                    ])
                    ->sum('quantity');
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->product_name,
            'price' => convertShillingToUSD($this->price),
            'image' => Storage::url($this->image),
            'category' => $this->category->name ?? null, // Assuming `category` relationship exists
            'total_sold' => $totalSold, // Sum of quantities from orderItems
            'total_shop_quantity' => $totalShopQuantity, // Total quantity from inventories where type is shop
            'total_stock_quantity' => $totalStockQuantity, // Total quantity from inventories where type is stock
        ];
    }
}
