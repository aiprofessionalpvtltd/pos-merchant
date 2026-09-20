<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\InventoryHistory;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

class StockService
{
    private const LOCATION_FIELDS = ['shop' => 'in_shop', 'stock' => 'in_stock', 'transportation' => 'in_transportation'];

    /**
     * @param  array{product_id: int, quantity: int, from: string, to: string, note?: ?string, idempotency_key: string}  $data
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function transfer(User $actor, Merchant $merchant, array $data): array
    {
        return Idempotency::run("m{$merchant->id}:transfer", $data['idempotency_key'], $data, fn () => DB::transaction(function () use ($actor, $merchant, $data) {
            $product = $this->lockedProduct($merchant, $data['product_id']);
            $quantities = $this->quantities($product);

            if ($quantities[$data['from']] < $data['quantity']) {
                throw new ApiException('inventory.insufficient_quantity', 'Not enough stock to move', 409, ['available' => $quantities[$data['from']]]);
            }

            $this->setQuantity($product, $data['from'], $quantities[$data['from']] - $data['quantity']);
            $this->setQuantity($product, $data['to'], $quantities[$data['to']] + $data['quantity']);

            $history = InventoryHistory::create([
                'product_id' => $product->id, 'quantity' => $data['quantity'], 'from_location' => $data['from'], 'to_location' => $data['to'],
                'user_id' => $actor->id, 'kind' => 'transfer', 'note' => $data['note'] ?? null,
            ]);

            $this->bump($product);

            return [
                'data' => [
                    'transfer_id' => $history->id,
                    'product_id' => $product->id,
                    'quantity' => $data['quantity'],
                    'from' => $data['from'],
                    'to' => $data['to'],
                    'quantities_after' => $this->named($this->quantities($product)),
                    'created_at' => ApiResponse::iso($history->created_at),
                ],
                'message' => "{$data['quantity']} moved from {$data['from']} to {$data['to']}",
                'status' => 200,
            ];
        }));
    }

    /**
     * @param  array{in_shop?: int, in_stock?: int, reason: string, note?: ?string, idempotency_key: string}  $data
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function adjust(User $actor, Merchant $merchant, int $productId, array $data): array
    {
        return Idempotency::run("m{$merchant->id}:adjust:{$productId}", $data['idempotency_key'], $data, fn () => DB::transaction(function () use ($actor, $merchant, $productId, $data) {
            $product = $this->lockedProduct($merchant, $productId);
            $before = $this->quantities($product);
            $adjustment = [];

            foreach (['in_shop' => 'shop', 'in_stock' => 'stock'] as $field => $location) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $delta = $data[$field] - $before[$location];
                if ($delta === 0) {
                    continue;
                }

                $this->setQuantity($product, $location, $data[$field]);
                $adjustment[$field] = $delta;

                InventoryHistory::create([
                    'product_id' => $product->id, 'quantity' => $delta, 'from_location' => $location, 'to_location' => $location,
                    'user_id' => $actor->id, 'kind' => 'adjustment', 'reason' => $data['reason'], 'note' => $data['note'] ?? null,
                ]);
            }

            if ($adjustment) {
                $this->bump($product);
            }

            return [
                'data' => [
                    'product_id' => $product->id,
                    'quantities' => $this->named($this->quantities($product)),
                    'adjustment' => $adjustment,
                    'reason' => $data['reason'],
                    'version' => $product->version,
                ],
                'message' => 'Quantities updated',
                'status' => 200,
            ];
        }));
    }

    /**
     * @param  array{type?: string, page?: int, per_page?: int}  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, summary: array<string, int>}
     */
    public function alerts(Merchant $merchant, array $filters): array
    {
        $rows = Product::where('merchant_id', $merchant->id)
            ->with(['inventories'])
            ->get()
            ->map(function (Product $product) {
                $quantities = $this->quantities($product);

                return [
                    'product' => $product,
                    'quantities' => $quantities,
                    'alarm' => $product->alarm_limit > 0 && $quantities['shop'] <= $product->alarm_limit,
                    'restock' => $product->stock_limit > 0 && $quantities['stock'] <= $product->stock_limit,
                ];
            });

        $isRestock = ($filters['type'] ?? 'alarm') === 'restock';
        $flag = $isRestock ? 'restock' : 'alarm';
        $location = $isRestock ? 'stock' : 'shop';
        $limit = $isRestock ? 'stock_limit' : 'alarm_limit';

        $alerts = $rows->where($flag, true)
            ->sortBy(fn (array $row) => $row['quantities'][$location])
            ->values();

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = $alerts->count();

        $items = $alerts->forPage($page, $perPage)->map(function (array $row) use ($location, $limit) {
            /** @var Product $product */
            $product = $row['product'];
            $quantity = $row['quantities'][$location];

            return [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'bar_code' => $product->bar_code,
                'quantities' => ['in_shop' => $row['quantities']['shop'], 'in_stock' => $row['quantities']['stock']],
                'limits' => ['stock_limit' => $product->stock_limit, 'alarm_limit' => $product->alarm_limit],
                'shortfall' => max($product->{$limit} - $quantity, 0),
                'severity' => $quantity === 0 ? 'critical' : 'warning',
            ];
        })->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(ceil($total / $perPage), 1),
                'has_more' => $page * $perPage < $total,
            ],
            'summary' => ['alarm_count' => $rows->where('alarm', true)->count(), 'restock_count' => $rows->where('restock', true)->count()],
        ];
    }

    /**
     * Stock a sale takes off the shelf. Used by orders; throws when the shelf is short.
     */
    public function takeFromShop(Product $product, int $quantity, ?User $actor = null, bool $allowShortfall = false): void
    {
        $product = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        $available = $this->quantities($product)['shop'];

        if ($available < $quantity && ! $allowShortfall) {
            throw new ApiException('product.out_of_stock', 'Not enough of this product in the shop', 409, ['available' => $available, 'product_id' => $product->id]);
        }

        $taken = min($quantity, $available);
        $this->setQuantity($product, 'shop', $available - $taken);

        InventoryHistory::create([
            'product_id' => $product->id, 'quantity' => $taken, 'from_location' => 'shop', 'to_location' => 'shop',
            'user_id' => $actor?->id, 'kind' => 'sale',
        ]);

        $this->bump($product);
    }

    /**
     * Puts a sold quantity back, when an order that had taken stock is deleted.
     */
    public function returnToShop(Product $product, int $quantity): void
    {
        $product = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();

        $this->setQuantity($product, 'shop', $this->quantities($product)['shop'] + $quantity);

        InventoryHistory::create([
            'product_id' => $product->id, 'quantity' => $quantity, 'from_location' => 'shop', 'to_location' => 'shop', 'kind' => 'return',
        ]);

        $this->bump($product);
    }

    /**
     * @return array{shop: int, stock: int, transportation: int}
     */
    public function quantities(Product $product): array
    {
        $byType = ProductInventory::where('product_id', $product->id)->pluck('quantity', 'type');

        return [
            'shop' => (int) ($byType['shop'] ?? 0),
            'stock' => (int) ($byType['stock'] ?? 0),
            'transportation' => (int) ($byType['transportation'] ?? 0),
        ];
    }

    private function lockedProduct(Merchant $merchant, int $id): Product
    {
        return Product::where('merchant_id', $merchant->id)->lockForUpdate()->find($id)
            ?? throw new ApiException('product.not_found', 'We could not find that product', 404);
    }

    private function setQuantity(Product $product, string $location, int $quantity): void
    {
        ProductInventory::updateOrCreate(['product_id' => $product->id, 'type' => $location], ['quantity' => $quantity]);
    }

    private function bump(Product $product): void
    {
        $product->forceFill(['version' => $product->version + 1])->save();
    }

    /**
     * @param  array{shop: int, stock: int, transportation: int}  $quantities
     * @return array{in_shop: int, in_stock: int, in_transportation: int}
     */
    private function named(array $quantities): array
    {
        $named = [];
        foreach (self::LOCATION_FIELDS as $location => $field) {
            $named[$field] = $quantities[$location];
        }

        return $named;
    }
}
