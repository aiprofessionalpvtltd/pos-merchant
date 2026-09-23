<?php

namespace App\Services;

use App\Models\File;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything the home screen draws, in one call.
 */
class DashboardService
{
    private const TOP_SELLING_LIMIT = 5;

    private const LATEST_CLIENTS_LIMIT = 5;

    public function __construct(private readonly StockService $stock) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Merchant $merchant, int $weeks, int $historyLimit): array
    {
        $timezone = $merchant->timezone;
        $to = now($timezone)->endOfDay();
        $from = $to->copy()->subDays($weeks * 7 - 1)->startOfDay();
        $previousFrom = $from->copy()->subDays($weeks * 7);
        $previousTo = $from->copy()->subSecond();

        $revenueNow = $this->revenueTotal($merchant, $from, $to);
        $revenuePrev = $this->revenueTotal($merchant, $previousFrom, $previousTo);
        $rate = $merchant->effectiveExchangeRate();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $timezone],
            'revenue' => [
                'total' => Money::usd($revenueNow),
                'total_alt' => Money::of((int) round($revenueNow * $rate / 100), config('exelo.alt_currency')),
                'change_pct' => $this->changePct($revenueNow, $revenuePrev),
                'trend' => $revenueNow >= $revenuePrev ? 'up' : 'down',
            ],
            'series' => ['granularity' => 'day', 'weeks' => $this->weeklySeries($merchant, $to, $weeks)],
            'orders' => $this->orderCounts($merchant),
            'products' => $this->productStats($merchant, $from, $to, $previousFrom, $previousTo),
            'alerts' => $this->stock->alerts($merchant, [])['summary'],
            'top_selling' => $this->topSelling($merchant, $from, $to),
            'transaction_history' => $this->orderRows($merchant, true, $historyLimit),
            'pending_transactions' => $this->orderRows($merchant, false, $historyLimit),
            'latest_clients' => $this->latestClients($merchant),
            'generated_at' => ApiResponse::iso(now()),
        ];
    }

    private function revenueTotal(Merchant $merchant, Carbon $from, Carbon $to): int
    {
        $total = Order::where('merchant_id', $merchant->id)
            ->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->sum('total_price');

        return Money::toMinor((float) $total, 'USD');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function weeklySeries(Merchant $merchant, Carbon $to, int $weeks): array
    {
        $currentWeekStart = $to->copy()->startOfWeek();
        $result = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $currentWeekStart->copy()->subWeeks($i);
            $weekEnd = $weekStart->copy()->endOfWeek();

            $rows = Order::where('merchant_id', $merchant->id)
                ->whereNotNull('paid_at')->whereBetween('paid_at', [$weekStart, $weekEnd])
                ->selectRaw('DATE(paid_at) as day, SUM(total_price) as sales, COUNT(*) as order_count')
                ->groupBy('day')->get()->keyBy('day');

            $productsSoldByDay = OrderItem::whereHas('order', fn ($q) => $q->where('merchant_id', $merchant->id)->whereNotNull('paid_at')->whereBetween('paid_at', [$weekStart, $weekEnd]))
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->selectRaw('DATE(orders.paid_at) as day, SUM(order_items.quantity) as qty')
                ->groupBy('day')->pluck('qty', 'day');

            $days = [];
            $weekTotal = 0;
            $cursor = $weekStart->copy();

            while ($cursor->lte($weekEnd)) {
                $key = $cursor->toDateString();
                $sales = Money::toMinor((float) ($rows[$key]->sales ?? 0), 'USD');
                $weekTotal += $sales;

                $days[] = [
                    'date' => $key,
                    'day' => $cursor->format('D'),
                    'sales' => Money::usd($sales),
                    'products_sold' => (int) ($productsSoldByDay[$key] ?? 0),
                    'order_count' => (int) ($rows[$key]->order_count ?? 0),
                ];

                $cursor->addDay();
            }

            $result[] = [
                'week_start' => $weekStart->toDateString(),
                'label' => $i === 0 ? 'This week' : ($i === 1 ? 'Last week' : null),
                'is_current' => $i === 0,
                'total' => Money::usd($weekTotal),
                'days' => $days,
            ];
        }

        return $result;
    }

    /**
     * @return array{pending_count: int, complete_count: int, pending_value: array<string, mixed>}
     */
    private function orderCounts(Merchant $merchant): array
    {
        $pending = Order::where('merchant_id', $merchant->id)->whereRaw('LOWER(order_status) = ?', ['pending']);
        $complete = Order::where('merchant_id', $merchant->id)->whereRaw("LOWER(order_status) IN ('complete', 'paid')");

        return [
            'pending_count' => $pending->count(),
            'complete_count' => $complete->count(),
            'pending_value' => Money::usd(Money::toMinor((float) $pending->sum('total_price'), 'USD')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productStats(Merchant $merchant, Carbon $from, Carbon $to, Carbon $previousFrom, Carbon $previousTo): array
    {
        $inShop = (int) $this->stockLevel($merchant, 'shop');
        $inStock = (int) $this->stockLevel($merchant, 'stock');

        $inShopAtFrom = $inShop - $this->netMovement($merchant, 'shop', $from, $to);
        $inStockAtFrom = $inStock - $this->netMovement($merchant, 'stock', $from, $to);
        $inShopAtPrevFrom = $inShopAtFrom - $this->netMovement($merchant, 'shop', $previousFrom, $previousTo);
        $inStockAtPrevFrom = $inStockAtFrom - $this->netMovement($merchant, 'stock', $previousFrom, $previousTo);

        $soldNow = $this->unitsSold($merchant, $from, $to);
        $soldPrev = $this->unitsSold($merchant, $previousFrom, $previousTo);

        $newShopNow = $this->openingStock($merchant, 'shop', $from, $to);
        $newShopPrev = $this->openingStock($merchant, 'shop', $previousFrom, $previousTo);
        $newStockNow = $this->openingStock($merchant, 'stock', $from, $to);
        $newStockPrev = $this->openingStock($merchant, 'stock', $previousFrom, $previousTo);

        return [
            'total_sold' => $soldNow,
            'total_sold_change_pct' => $this->changePct($soldNow, $soldPrev),
            'in_shop' => $inShop,
            'in_shop_change_pct' => $this->changePct($inShop, $inShopAtFrom),
            'in_stock' => $inStock,
            'in_stock_change_pct' => $this->changePct($inStock, $inStockAtFrom),
            'new_in_shop' => $newShopNow,
            'new_in_shop_change_pct' => $this->changePct($newShopNow, $newShopPrev),
            'new_in_stock' => $newStockNow,
            'new_in_stock_change_pct' => $this->changePct($newStockNow, $newStockPrev),
        ];
    }

    private function stockLevel(Merchant $merchant, string $location): int
    {
        return (int) DB::table('product_inventories')
            ->join('products', 'products.id', '=', 'product_inventories.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->where('product_inventories.type', $location)
            ->sum('product_inventories.quantity');
    }

    /**
     * The net change to a location's stock during a period, from the inventory history:
     * opening and returns add, sales subtract, transfers move between locations, adjustments are signed.
     */
    private function netMovement(Merchant $merchant, string $location, Carbon $from, Carbon $to): int
    {
        $rows = DB::table('inventory_histories')
            ->join('products', 'products.id', '=', 'inventory_histories.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->whereBetween('inventory_histories.created_at', [$from, $to])
            ->where(fn ($q) => $q->where('from_location', $location)->orWhere('to_location', $location))
            ->select('kind', 'from_location', 'to_location', 'quantity')
            ->get();

        $net = 0;

        foreach ($rows as $row) {
            $net += match ($row->kind) {
                'opening', 'adjustment' => $row->from_location === $location ? (int) $row->quantity : 0,
                'return' => $row->to_location === $location ? (int) $row->quantity : 0,
                'sale' => $row->from_location === $location ? -(int) $row->quantity : 0,
                'transfer' => ($row->to_location === $location ? (int) $row->quantity : 0) - ($row->from_location === $location ? (int) $row->quantity : 0),
                default => 0,
            };
        }

        return $net;
    }

    private function openingStock(Merchant $merchant, string $location, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('inventory_histories')
            ->join('products', 'products.id', '=', 'inventory_histories.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->where('inventory_histories.kind', 'opening')
            ->where('inventory_histories.to_location', $location)
            ->whereBetween('inventory_histories.created_at', [$from, $to])
            ->sum('inventory_histories.quantity');
    }

    private function unitsSold(Merchant $merchant, Carbon $from, Carbon $to): int
    {
        return (int) OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.merchant_id', $merchant->id)
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->sum('order_items.quantity');
    }

    private function changePct(int|float $now, int|float $previous): float
    {
        if ($previous == 0) {
            return $now == 0 ? 0.0 : 100.0;
        }

        return round(($now - $previous) / abs($previous) * 100, 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topSelling(Merchant $merchant, Carbon $from, Carbon $to): array
    {
        $rows = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.merchant_id', $merchant->id)
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->selectRaw('products.id as product_id, products.product_name, products.price, products.image, products.image_file_id, SUM(order_items.quantity) as quantity_sold, SUM(order_items.quantity * order_items.price) as revenue')
            ->groupBy('products.id', 'products.product_name', 'products.price', 'products.image', 'products.image_file_id')
            ->orderByDesc('quantity_sold')
            ->limit(self::TOP_SELLING_LIMIT)
            ->get();

        $productIds = $rows->pluck('product_id');
        $quantities = DB::table('product_inventories')->whereIn('product_id', $productIds)->get()->groupBy('product_id');

        return $rows->map(function ($row) use ($quantities) {
            $byType = ($quantities[$row->product_id] ?? collect())->pluck('quantity', 'type');

            return [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'quantity_sold' => (int) $row->quantity_sold,
                'price' => Money::usd(Money::toMinor((float) $row->price, 'USD')),
                'revenue' => Money::usd(Money::toMinor((float) $row->revenue, 'USD')),
                'quantities' => ['in_shop' => (int) ($byType['shop'] ?? 0), 'in_stock' => (int) ($byType['stock'] ?? 0)],
                'image' => ['thumb_url' => $this->thumbUrl($row->image_file_id, $row->image)],
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderRows(Merchant $merchant, bool $paid, int $limit): array
    {
        $query = Order::where('merchant_id', $merchant->id)
            ->when($paid, fn ($q) => $q->whereNotNull('paid_at'), fn ($q) => $q->whereRaw('LOWER(order_status) = ?', ['pending']))
            ->orderByDesc($paid ? 'paid_at' : 'created_at')
            ->limit($limit);

        return $query->get()->map(function (Order $order) use ($paid, $merchant) {
            $row = [
                'order_id' => $order->id,
                'name' => $order->name,
                'initial_name' => $this->initials($order->name),
                'payment_method' => $order->payment_method,
                'order_date' => ApiResponse::iso($paid ? $order->paid_at : $order->created_at),
                'order_date_display' => ($paid ? $order->paid_at : $order->created_at)?->copy()->setTimezone($merchant->timezone)->format('j M Y, H:i'),
                'amount' => Money::usd(Money::toMinor((float) $order->total_price, 'USD')),
            ];

            if ($paid) {
                $row['status'] = 'Complete';
            }

            return $row;
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestClients(Merchant $merchant): array
    {
        return Order::where('merchant_id', $merchant->id)
            ->whereNotNull('name')
            ->orderByDesc('created_at')
            ->limit(self::LATEST_CLIENTS_LIMIT)
            ->get()
            ->map(fn (Order $order) => [
                'order_id' => $order->id,
                'name' => $order->name,
                'initial_name' => $this->initials($order->name),
                'payment_method' => $order->payment_method,
            ])->all();
    }

    private function initials(?string $name): string
    {
        return collect(explode(' ', (string) $name))->filter()->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))->take(2)->implode('');
    }

    private function thumbUrl(?string $imageFileId, ?string $legacyImage): ?string
    {
        if ($imageFileId) {
            $file = File::where('public_id', $imageFileId)->first();

            return $file ? ($file->thumbUrl() ?? $file->url()) : null;
        }

        return $legacyImage ? \Illuminate\Support\Facades\Storage::disk('public')->url($legacyImage) : null;
    }
}
