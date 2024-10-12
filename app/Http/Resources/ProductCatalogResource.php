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

        $totalStockQuantity = $this->inventories
            ->where('type', 'stock')
            ->sum('quantity');

        // Check total sold based on order items
        $totalSold = $this->orderItems->sum('quantity');

        // Handle the case when total shop and stock quantities are both zero
        if ($totalShopQuantity == 0 && $totalStockQuantity == 0) {
            // Fetch the start_date and end_date from the request
            $startDate = request()->query('start_date');
            $endDate = request()->query('end_date');

            // Validate and format the date
            if ($startDate && $endDate) {
                try {
                    $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                    $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();

                    // Calculate total sold within the given date range
                    $totalSold = $this->orderItems()
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->sum('quantity');
                } catch (\Exception $e) {
                    dd('kk');
                    // Handle any date parsing or query errors
                    $totalSold = $this->orderItems->sum('quantity'); // Default to total sold without date filter
                }
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
