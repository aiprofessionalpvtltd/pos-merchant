<?php

namespace App\Services;

use App\Models\Category;
use App\Models\File;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The four report screens: sales, inventory, product-level stats and the
 * catalogue with sales attached. JSON only for now — `format=csv`/`pdf` and
 * the async export job are not built (see docs/dashboard.md).
 */
class ReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function sales(Merchant $merchant, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $groupBy = $filters['group_by'] ?? 'day';

        $orders = Order::where('merchant_id', $merchant->id)->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);

        $gross = Money::toMinor((float) (clone $orders)->sum('total_price'), 'USD');
        $vat = Money::toMinor((float) (clone $orders)->sum('vat'), 'USD');
        $fees = Money::toMinor((float) (clone $orders)->sum('exelo_amount'), 'USD');
        $orderCount = (clone $orders)->count();
        $productsSold = (int) OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->sum('order_items.quantity');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'gross' => Money::usd($gross),
                'vat' => Money::usd($vat),
                'fees' => Money::usd($fees),
                'net' => Money::usd($gross - $vat - $fees),
                'order_count' => $orderCount,
                'average_order' => Money::usd($orderCount > 0 ? intdiv($gross, $orderCount) : 0),
                'products_sold' => $productsSold,
            ],
            'by_payment_method' => (clone $orders)
                ->selectRaw('payment_method as method, COUNT(*) as order_count, SUM(total_price) as total')
                ->groupBy('payment_method')->get()
                ->map(fn ($row) => ['method' => $row->method, 'order_count' => (int) $row->order_count, 'total' => Money::usd(Money::toMinor((float) $row->total, 'USD'))])
                ->values()->all(),
            'rows' => $this->salesRows($merchant, $from, $to, $groupBy),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salesRows(Merchant $merchant, Carbon $from, Carbon $to, string $groupBy): array
    {
        $query = Order::where('merchant_id', $merchant->id)->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);

        if ($groupBy === 'payment_method') {
            $rows = (clone $query)->selectRaw('payment_method as bucket, COUNT(*) as order_count, SUM(total_price) as gross, SUM(total_price - vat - exelo_amount) as net')
                ->groupBy('payment_method')->get();

            return $rows->map(fn ($row) => $this->salesRow('payment_method', (string) $row->bucket, ucfirst((string) $row->bucket), $merchant, $from, $to, $row))->all();
        }

        if ($groupBy === 'employee') {
            $rows = (clone $query)->with('user')->selectRaw('user_id, COUNT(*) as order_count, SUM(total_price) as gross, SUM(total_price - vat - exelo_amount) as net')
                ->groupBy('user_id')->get();

            return $rows->map(fn ($row) => $this->salesRow('user_id', (string) $row->user_id, $row->user?->name ?? 'Unknown', $merchant, $from, $to, $row))->all();
        }

        $format = match ($groupBy) {
            'month' => '%Y-%m-01',
            'week' => '%X-%V',
            default => '%Y-%m-%d',
        };

        $rows = (clone $query)
            ->selectRaw("DATE_FORMAT(paid_at, '$format') as bucket, MIN(paid_at) as bucket_date, COUNT(*) as order_count, SUM(total_price) as gross, SUM(total_price - vat - exelo_amount) as net")
            ->groupBy('bucket')->orderBy('bucket_date')->get();

        $productsSoldByBucket = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(orders.paid_at, '$format') as bucket, SUM(order_items.quantity) as qty")
            ->groupBy('bucket')->pluck('qty', 'bucket');

        return $rows->map(function ($row) use ($groupBy, $productsSoldByBucket) {
            $date = Carbon::parse($row->bucket_date);
            $label = match ($groupBy) {
                'month' => $date->format('M Y'),
                'week' => 'Week of '.$date->startOfWeek()->format('j M'),
                default => $date->format('j M'),
            };

            return $this->numericRow($date->toDateString(), $label, $row, (int) ($productsSoldByBucket[$row->bucket] ?? 0));
        })->all();
    }

    private function salesRow(string $column, string $bucket, string $label, Merchant $merchant, Carbon $from, Carbon $to, $row): array
    {
        $productsSold = (int) OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->where("orders.$column", $bucket)
            ->sum('order_items.quantity');

        return $this->numericRow($bucket, $label, $row, $productsSold);
    }

    private function numericRow(string $bucket, string $label, $row, ?int $productsSold = null): array
    {
        return [
            'bucket' => $bucket,
            'label' => $label,
            'order_count' => (int) $row->order_count,
            'products_sold' => $productsSold ?? 0,
            'gross' => Money::usd(Money::toMinor((float) $row->gross, 'USD')),
            'net' => Money::usd(Money::toMinor((float) $row->net, 'USD')),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function inventory(Merchant $merchant, array $filters): array
    {
        [$from, $to] = $this->period($filters);

        $products = Product::where('merchant_id', $merchant->id)
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->with(['category', 'inventories'])
            ->get();

        $rate = $merchant->effectiveExchangeRate();
        $shopValue = 0;
        $stockValue = 0;
        $rows = [];

        $soldByProduct = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')->groupBy('order_items.product_id')->pluck('qty', 'product_id');

        foreach ($products as $product) {
            $byType = $product->inventories->pluck('quantity', 'type');
            $inShop = (int) ($byType['shop'] ?? 0);
            $inStock = (int) ($byType['stock'] ?? 0);
            $priceCents = Money::toMinor((float) $product->price, 'USD');

            $shopValue += $inShop * $priceCents;
            $stockValue += $inStock * $priceCents;

            $rows[] = [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'category' => $product->category?->name,
                'in_shop' => $inShop,
                'in_stock' => $inStock,
                'sold' => (int) ($soldByProduct[$product->id] ?? 0),
                'unit_price' => Money::usd($priceCents),
                'value' => Money::usd(($inShop + $inStock) * $priceCents),
            ];
        }

        $movement = $this->movementCounts($merchant, $filters['category_id'] ?? null, $from, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'product_count' => $products->count(),
                'units_in_shop' => (int) $products->flatMap(fn (Product $p) => $p->inventories)->where('type', 'shop')->sum('quantity'),
                'units_in_stock' => (int) $products->flatMap(fn (Product $p) => $p->inventories)->where('type', 'stock')->sum('quantity'),
                'units_in_transportation' => (int) $products->flatMap(fn (Product $p) => $p->inventories)->where('type', 'transportation')->sum('quantity'),
                'shop_value' => Money::usd($shopValue),
                'stock_value' => Money::usd($stockValue),
                'total_value' => Money::usd($shopValue + $stockValue),
            ],
            'movement' => $movement,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{added: int, sold: int, transferred: int, adjusted: int}
     */
    private function movementCounts(Merchant $merchant, ?int $categoryId, Carbon $from, Carbon $to): array
    {
        $query = DB::table('inventory_histories')
            ->join('products', 'products.id', '=', 'inventory_histories.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->whereBetween('inventory_histories.created_at', [$from, $to]);

        return [
            'added' => (int) (clone $query)->where('inventory_histories.kind', 'opening')->sum('inventory_histories.quantity'),
            'sold' => (int) (clone $query)->where('inventory_histories.kind', 'sale')->sum('inventory_histories.quantity'),
            'transferred' => (int) (clone $query)->where('inventory_histories.kind', 'transfer')->sum('inventory_histories.quantity'),
            'adjusted' => (int) (clone $query)->where('inventory_histories.kind', 'adjustment')->sum(DB::raw('ABS(inventory_histories.quantity)')),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<string, mixed>, pagination: array<string, mixed>}
     */
    public function products(Merchant $merchant, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $metric = $filters['metric'] ?? 'sold';

        $rows = match ($metric) {
            'in_shop' => $this->stockRows($merchant, 'shop', $filters),
            'in_stock' => $this->stockRows($merchant, 'stock', $filters),
            'new_shop' => $this->newStockRows($merchant, 'shop', $from, $to, $filters),
            'new_stock' => $this->newStockRows($merchant, 'stock', $from, $to, $filters),
            default => $this->soldRows($merchant, $from, $to, $filters),
        };

        $sort = $filters['sort'] ?? '-value';
        $column = ltrim($sort, '-') === 'value' ? 'value_cents' : 'units';
        $rows = str_starts_with($sort, '-') ? $rows->sortByDesc($column)->values() : $rows->sortBy($column)->values();

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = $rows->count();
        $slice = $rows->forPage($page, $perPage)->values();

        return [
            'data' => [
                'metric' => $metric,
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals' => [
                    'product_count' => $total,
                    'units' => (int) $rows->sum('units'),
                    'value' => Money::usd((int) $rows->sum('value_cents')),
                ],
                'rows' => $slice->map(fn (array $row) => collect($row)->except('value_cents')->all())->all(),
            ],
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'total_pages' => (int) max(ceil($total / $perPage), 1), 'has_more' => $page * $perPage < $total,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function soldRows(Merchant $merchant, Carbon $from, Carbon $to, array $filters): \Illuminate\Support\Collection
    {
        $rows = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('products.category_id', $categoryId))
            ->selectRaw('products.id as product_id, products.product_name, products.bar_code, products.category_id, products.price, products.image, products.image_file_id, SUM(order_items.quantity) as units')
            ->groupBy('products.id', 'products.product_name', 'products.bar_code', 'products.category_id', 'products.price', 'products.image', 'products.image_file_id')
            ->get();

        return $this->presentRows($rows, 'units');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stockRows(Merchant $merchant, string $location, array $filters): \Illuminate\Support\Collection
    {
        $rows = Product::where('merchant_id', $merchant->id)
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->whereHas('inventories', fn ($q) => $q->where('type', $location)->where('quantity', '>', 0))
            ->with('inventories')
            ->get()
            ->map(fn (Product $product) => (object) [
                'product_id' => $product->id, 'product_name' => $product->product_name, 'bar_code' => $product->bar_code,
                'category_id' => $product->category_id, 'price' => $product->price, 'image' => $product->image, 'image_file_id' => $product->image_file_id,
                'units' => $product->inventories->firstWhere('type', $location)?->quantity ?? 0,
            ]);

        return $this->presentRows($rows, 'units');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function newStockRows(Merchant $merchant, string $location, Carbon $from, Carbon $to, array $filters): \Illuminate\Support\Collection
    {
        $productIds = DB::table('inventory_histories')
            ->join('products', 'products.id', '=', 'inventory_histories.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->where('inventory_histories.kind', 'opening')->where('inventory_histories.to_location', $location)
            ->whereBetween('inventory_histories.created_at', [$from, $to])
            ->selectRaw('products.id as product_id, SUM(inventory_histories.quantity) as units')
            ->groupBy('products.id')->pluck('units', 'product_id');

        $rows = Product::whereIn('id', $productIds->keys())
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->get()
            ->map(fn (Product $product) => (object) [
                'product_id' => $product->id, 'product_name' => $product->product_name, 'bar_code' => $product->bar_code,
                'category_id' => $product->category_id, 'price' => $product->price, 'image' => $product->image, 'image_file_id' => $product->image_file_id,
                'units' => (int) $productIds[$product->id],
            ]);

        return $this->presentRows($rows, 'units');
    }

    private function presentRows(iterable $rows, string $unitsField): \Illuminate\Support\Collection
    {
        $rows = collect($rows);
        $productIds = $rows->pluck('product_id');
        $categories = Category::whereIn('id', $rows->pluck('category_id')->filter())->pluck('name', 'id');
        $quantities = DB::table('product_inventories')->whereIn('product_id', $productIds)->get()->groupBy('product_id');

        return $rows->map(function ($row) use ($categories, $quantities, $unitsField) {
            $byType = ($quantities[$row->product_id] ?? collect())->pluck('quantity', 'type');
            $unitPriceCents = Money::toMinor((float) $row->price, 'USD');
            $units = (int) $row->{$unitsField};

            return [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'bar_code' => $row->bar_code,
                'category' => $row->category_id ? ['id' => $row->category_id, 'name' => $categories[$row->category_id] ?? null] : null,
                'units' => $units,
                'unit_price' => Money::usd($unitPriceCents),
                'value' => Money::usd($units * $unitPriceCents),
                'value_cents' => $units * $unitPriceCents,
                'quantities' => ['in_shop' => (int) ($byType['shop'] ?? 0), 'in_stock' => (int) ($byType['stock'] ?? 0)],
                'image' => ['thumb_url' => $this->thumbUrl($row->image_file_id, $row->image)],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function catalogue(Merchant $merchant, array $filters): array
    {
        [$from, $to] = $this->period($filters);

        $categories = Category::where('merchant_id', $merchant->id)
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->whereKey($categoryId))
            ->with(['products' => fn ($q) => $q->when($filters['q'] ?? null, fn ($inner, $term) => $inner->where('product_name', 'like', "%$term%"))->with('inventories')])
            ->get();

        $soldByProduct = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty, SUM(order_items.quantity * order_items.price) as revenue')
            ->groupBy('order_items.product_id')->get()->keyBy('product_id');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'categories' => $categories->map(function (Category $category) use ($soldByProduct) {
                $products = $category->products->map(function (Product $product) use ($soldByProduct) {
                    $sold = $soldByProduct[$product->id] ?? null;
                    $byType = $product->inventories->pluck('quantity', 'type');

                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'price' => Money::usd(Money::toMinor((float) $product->price, 'USD')),
                        'total_sold' => (int) ($sold->qty ?? 0),
                        'quantities' => ['in_shop' => (int) ($byType['shop'] ?? 0), 'in_stock' => (int) ($byType['stock'] ?? 0)],
                        'image' => ['thumb_url' => $this->thumbUrl($product->image_file_id, $product->image)],
                        'revenue_cents' => Money::toMinor((float) ($sold->revenue ?? 0), 'USD'),
                    ];
                });

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'product_count' => $products->count(),
                    'units_sold' => $products->sum('total_sold'),
                    'revenue' => Money::usd($products->sum('revenue_cents')),
                    'products' => $products->map(fn (array $p) => collect($p)->except('revenue_cents')->all())->all(),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }

    private function thumbUrl(?string $imageFileId, ?string $legacyImage): ?string
    {
        if ($imageFileId) {
            $file = File::where('public_id', $imageFileId)->first();

            return $file ? ($file->thumbUrl() ?? $file->url()) : null;
        }

        return $legacyImage ? Storage::disk('public')->url($legacyImage) : null;
    }
}
