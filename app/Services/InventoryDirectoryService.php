<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\InventoryHistory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the admin product pages show. Read only: nothing here changes stock.
 * `listRow` expects a product from `listQuery()`, which carries the three stock columns.
 */
class InventoryDirectoryService
{
    private const RECENT_MOVEMENTS = 50;

    public const STATUSES = ['ok' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock', 'deleted' => 'Deleted'];

    /**
     * Every product of every shop, deleted ones included, with stock per location.
     */
    public function listQuery(): Builder
    {
        return Product::withTrashed()
            ->with(['merchant', 'category'])
            ->select('products.*')
            ->selectSub($this->stockSub('shop'), 'qty_shop')
            ->selectSub($this->stockSub('stock'), 'qty_stock')
            ->selectSub($this->stockSub('transportation'), 'qty_transit');
    }

    public function filterStatus(Builder $query, ?string $status): void
    {
        $shelf = "(select coalesce(sum(quantity), 0) from product_inventories where product_inventories.product_id = products.id and product_inventories.type = 'shop')";

        match ($status) {
            'deleted' => $query->whereNotNull('products.deleted_at'),
            'out' => $query->whereNull('products.deleted_at')->whereRaw("$shelf = 0"),
            'low' => $query->whereNull('products.deleted_at')->where('products.alarm_limit', '>', 0)->whereRaw("$shelf > 0 and $shelf <= products.alarm_limit"),
            'ok' => $query->whereNull('products.deleted_at')->whereRaw("$shelf > 0 and ($shelf > products.alarm_limit or products.alarm_limit = 0)"),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Product $product): array
    {
        $shelf = (int) $product->qty_shop;

        return [
            'merchant' => $product->merchant?->business_name ?: trim($product->merchant?->first_name.' '.$product->merchant?->last_name),
            'category' => $product->category?->name,
            'price' => Money::usd(Money::toMinor((float) $product->price, 'USD'))['display'],
            'shop' => $shelf,
            'stock' => (int) $product->qty_stock,
            'transit' => (int) $product->qty_transit,
            'status' => $this->status($product, $shelf),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Product $product): array
    {
        $product->loadMissing(['merchant', 'category']);
        $quantities = $product->inventories()->pluck('quantity', 'type');
        $shelf = (int) ($quantities['shop'] ?? 0);

        $sold = OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($order) => $order->whereRaw("LOWER(order_status) IN ('complete','paid')"))
            ->selectRaw('COALESCE(SUM(quantity), 0) as units, COALESCE(SUM(quantity * price), 0) as revenue')
            ->first();

        return [
            'price' => Money::usd(Money::toMinor((float) $product->price, 'USD'))['display'],
            'price_with_vat' => Money::usd(Money::toMinor((float) $product->total_price, 'USD'))['display'],
            'price_sls' => number_format((float) $product->price_sls).' SLSH',
            'status' => $this->status($product, $shelf),
            'quantities' => ['shop' => $shelf, 'stock' => (int) ($quantities['stock'] ?? 0), 'transportation' => (int) ($quantities['transportation'] ?? 0)],
            'sold_units' => (int) $sold->units,
            'sold_revenue' => Money::usd(Money::toMinor((float) $sold->revenue, 'USD'))['display'],
            'open_tickets' => CartItem::where('product_id', $product->id)->distinct('cart_id')->count('cart_id'),
            'movements' => InventoryHistory::where('product_id', $product->id)->with('user')->latest('id')->limit(self::RECENT_MOVEMENTS)->get(),
        ];
    }

    /**
     * @return array{key: string, label: string, color: string}
     */
    public function status(Product $product, int $shelf): array
    {
        $key = match (true) {
            $product->trashed() => 'deleted',
            $shelf === 0 => 'out',
            $product->alarm_limit > 0 && $shelf <= $product->alarm_limit => 'low',
            default => 'ok',
        };

        return ['key' => $key, 'label' => self::STATUSES[$key], 'color' => ['ok' => 'success', 'low' => 'warning', 'out' => 'danger', 'deleted' => 'secondary'][$key]];
    }

    private function stockSub(string $location)
    {
        return \DB::table('product_inventories')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('product_inventories.product_id', 'products.id')
            ->where('product_inventories.type', $location);
    }
}
