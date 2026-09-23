<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\File;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    private const COMPLETE_STATUSES = ['complete', 'paid'];

    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, summary: array<string, mixed>}
     */
    public function paginate(Merchant $merchant, array $filters): array
    {
        $query = Order::where('merchant_id', $merchant->id)->withCount('items')->withSum('items', 'quantity')->with('user.employee');

        if (isset($filters['status'])) {
            $this->whereStatus($query, $filters['status']);
        }

        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (isset($filters['q'])) {
            $term = $filters['q'];
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('name', 'like', "%$term%")->orWhere('mobile_number', 'like', "%$term%");

                if (ctype_digit($term)) {
                    $inner->orWhere('id', (int) $term);
                }
            });
        }

        if (isset($filters['employee_id'])) {
            $userId = Employee::where('merchant_id', $merchant->id)->whereKey($filters['employee_id'])->value('user_id');
            $query->where('user_id', $userId ?? 0);
        }

        $sort = $filters['sort'] ?? '-created_at';
        $column = ltrim($sort, '-') === 'total' ? 'total_price' : 'created_at';
        $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id');

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);
        $page = $query->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $rate = $merchant->effectiveExchangeRate();

        return [
            'items' => $page->getCollection()->map(fn (Order $order) => $this->summary($order, $merchant, $rate))->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
            'summary' => $this->summaryCounts($merchant),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Merchant $merchant, int $id): array
    {
        return $this->detail($this->find($merchant, $id), $merchant);
    }

    /**
     * Orders composed outside the register, typically replayed from a device that was offline.
     *
     * @param  array<string, mixed>  $data  validated order fields
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function create(User $actor, Merchant $merchant, array $data): array
    {
        return DB::transaction(function () use ($actor, $merchant, $data) {
            $existing = Order::where('merchant_id', $merchant->id)->where('client_order_id', $data['client_order_id'])->first();

            if ($existing) {
                return ['data' => $this->detail($existing, $merchant) + ['client_order_id' => $data['client_order_id']], 'message' => 'Order already saved', 'status' => 200];
            }

            $lines = [];
            foreach ($data['items'] as $item) {
                $product = Product::where('merchant_id', $merchant->id)->find($item['product_id'])
                    ?? throw new ApiException('product.not_found', 'We could not find that product', 404, ['product_id' => $item['product_id']]);

                $lines[] = ['product_id' => $product->id, 'quantity' => (int) $item['quantity'], 'unit_cents' => Money::toMinor((float) $product->price, 'USD'), 'vat_percent' => (int) $product->vat];
            }

            $order = $this->createFromLines($actor, $merchant, $lines, $data['customer'] ?? [], [
                'order_status' => 'Pending',
                'order_type' => 'shop',
                'note' => $data['note'] ?? null,
                'client_order_id' => $data['client_order_id'],
            ]);

            if (isset($data['created_at'])) {
                $order->forceFill(['created_at' => $data['created_at']])->save();
            }

            return ['data' => $this->detail($order, $merchant) + ['client_order_id' => $data['client_order_id']], 'message' => 'Order saved', 'status' => 201];
        });
    }

    /**
     * Writes an order and its lines. Prices are USD minor units as they were on the ticket.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_cents: int, vat_percent: int}>  $lines
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $attributes
     */
    public function createFromLines(User $actor, Merchant $merchant, array $lines, array $customer, array $attributes, int $feeCents = 0): Order
    {
        $subtotal = 0;
        $vat = 0;

        foreach ($lines as $line) {
            $lineCents = $line['unit_cents'] * $line['quantity'];
            $subtotal += $lineCents;
            $vat += (int) round($lineCents * $line['vat_percent'] / 100);
        }

        $total = $subtotal + $vat;
        $rate = $merchant->effectiveExchangeRate();

        $order = Order::create($attributes + [
            'merchant_id' => $merchant->id,
            'user_id' => $actor->id,
            'version' => 1,
            'name' => $customer['name'] ?? null,
            'mobile_number' => $customer['mobile_number'] ?? null,
            'sub_total' => $subtotal / 100,
            'vat' => $vat / 100,
            'exelo_amount' => $feeCents / 100,
            'total_price' => $total / 100,
            'total_price_sls' => round($total * $rate / 100, 2),
            'exchange_rate' => $rate,
        ]);

        foreach ($lines as $line) {
            OrderItem::create(['order_id' => $order->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'price' => $line['unit_cents'] / 100]);
        }

        return $order;
    }

    /**
     * Money was received: the order is Complete and paid, and stock leaves the shelf once.
     */
    public function settle(Order $order, string $rail, ?User $actor = null): Order
    {
        $order->forceFill([
            'order_status' => 'Complete',
            'paid_at' => $order->paid_at ?? now(),
            'payment_method' => $rail,
            'version' => $order->version + 1,
        ])->save();

        $this->deductStock($order, $actor);

        return $order;
    }

    /**
     * Takes the sold quantities off the shelf, once. A shortfall never blocks a sale that was already paid.
     */
    public function deductStock(Order $order, ?User $actor = null): void
    {
        if ($order->stock_deducted_at) {
            return;
        }

        foreach ($order->items as $item) {
            $product = Product::withTrashed()->find($item->product_id);

            if ($product) {
                $this->stock->takeFromShop($product, $item->quantity, $actor, allowShortfall: true);
            }
        }

        $order->forceFill(['stock_deducted_at' => now()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(Merchant $merchant, int $id, string $status, ?string $reason): array
    {
        return DB::transaction(function () use ($merchant, $id, $status, $reason) {
            $order = Order::where('merchant_id', $merchant->id)->lockForUpdate()->find($id)
                ?? throw new ApiException('order.not_found', 'We could not find that order', 404);

            $current = $order->isPending() ? 'pending' : ($order->isCancelled() ? 'cancelled' : 'complete');
            $allowed = match ($current) {
                'pending' => ['complete', 'cancelled'],
                'complete' => ['pending'],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw new ApiException('order.invalid_transition', "An order cannot go from $current to $status", 409, ['from' => $current, 'to' => $status, 'allowed' => $allowed]);
            }

            if ($status === 'cancelled' && ! $reason) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['reason' => ['Give a reason for cancelling']], 'reason');
            }

            $order->order_status = ucfirst($status);
            $order->version++;
            $order->cancel_reason = $status === 'cancelled' ? $reason : null;
            $order->save();

            if ($status === 'complete') {
                $this->deductStock($order->load('items'));
            }

            return ['id' => $order->id, 'order_status' => $order->order_status, 'version' => $order->version, 'paid_at' => ApiResponse::iso($order->paid_at)];
        });
    }

    /**
     * @return array{id: int, deleted: bool}
     */
    public function delete(Merchant $merchant, int $id): array
    {
        return DB::transaction(function () use ($merchant, $id) {
            $order = Order::where('merchant_id', $merchant->id)->lockForUpdate()->find($id)
                ?? throw new ApiException('order.not_found', 'We could not find that order', 404);

            if (! $order->isPending()) {
                throw new ApiException('order.cannot_delete_complete', 'Only a pending order can be deleted. Completed orders are history.', 409);
            }

            $this->restock($order);
            Invoice::where('order_id', $order->id)->update(['order_id' => null]);
            $order->items()->delete();
            $order->delete();

            return ['id' => $id, 'deleted' => true];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function receipt(Merchant $merchant, int $id): array
    {
        $order = $this->find($merchant, $id);
        $rate = $merchant->effectiveExchangeRate();
        $totals = $this->totals($order, $rate);
        $charge = $this->latestCharge($order);
        $vatLabel = rtrim(rtrim(number_format($merchant->vat_rate * 100, 2, '.', ''), '0'), '.').'%';
        $preferences = array_replace_recursive(config('exelo.preference_defaults'), $merchant->preferences ?? []);

        return [
            'merchant' => [
                'business_name' => $merchant->business_name,
                'location' => $merchant->location,
                'zaad_number' => $merchant->zaad_number,
                'edahab_number' => $merchant->edahab_number,
                'merchant_code' => $merchant->merchant_code,
            ],
            'invoice' => [
                'invoice_no' => 'INV-'.$order->id,
                'invoice_date' => ApiResponse::iso($order->created_at),
                'invoice_date_display' => $this->display($order->created_at, $merchant),
                'payment_status' => $order->paid_at ? 'Paid' : 'Unpaid',
                'payment_method' => $order->payment_method,
            ],
            'customer' => [
                'account' => 'ACC-'.$order->id,
                'name' => $order->name,
                'mobile_number' => $order->mobile_number,
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_name' => $item->product?->product_name,
                'quantity' => $item->quantity,
                'unit_price' => Money::usd(Money::toMinor((float) $item->price, 'USD')),
                'line_total' => Money::usd(Money::toMinor((float) $item->price, 'USD') * $item->quantity),
            ])->all(),
            'totals' => $totals + ['vat_label' => $vatLabel],
            'signature_url' => $this->signatureUrl($order),
            'charge' => $charge,
            'footer' => $preferences['receipt']['footer'] ?? 'Thank you for shopping with '.$merchant->business_name,
        ];
    }

    public function find(Merchant $merchant, int $id): Order
    {
        return Order::where('merchant_id', $merchant->id)
            ->with(['items.product' => fn ($query) => $query->withTrashed(), 'user.employee'])
            ->find($id)
            ?? throw new ApiException('order.not_found', 'We could not find that order', 404);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Order $order, Merchant $merchant): array
    {
        $order->loadMissing(['items.product' => fn ($query) => $query->withTrashed(), 'user.employee']);
        $rate = $merchant->effectiveExchangeRate();

        return $this->header($order, $merchant) + [
            'items' => $order->items->map(function (OrderItem $item) use ($rate) {
                $unit = Money::toMinor((float) $item->price, 'USD');

                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => Money::usd($unit),
                    'line_total' => Money::usd($unit * $item->quantity),
                    'line_total_alt' => $this->alt($unit * $item->quantity, $rate),
                ];
            })->all(),
            'totals' => $this->totals($order, $rate),
            'signature' => $this->signatureBlock($order),
            'charge' => $this->latestCharge($order),
            'note' => $order->note,
            'employee' => $this->employee($order),
            'created_at' => ApiResponse::iso($order->created_at),
            'created_at_display' => $this->display($order->created_at, $merchant),
            'paid_at' => ApiResponse::iso($order->paid_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Order $order, Merchant $merchant, int $rate): array
    {
        $total = Money::toMinor((float) $order->total_price, 'USD');

        return $this->header($order, $merchant) + [
            'item_count' => $order->items_count,
            'unit_count' => (int) $order->items_sum_quantity,
            'total' => Money::usd($total),
            'total_alt' => $this->alt($total, $rate),
            'has_signature' => false,
            'created_at' => ApiResponse::iso($order->created_at),
            'created_at_display' => $this->display($order->created_at, $merchant),
            'employee' => $this->employee($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Order $order, Merchant $merchant): array
    {
        return [
            'id' => $order->id,
            'version' => $order->version,
            'order_status' => $order->isComplete() ? 'Complete' : ucfirst(strtolower($order->order_status)),
            'name' => $order->name,
            'initial_name' => collect(explode(' ', (string) $order->name))->filter()->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))->take(2)->implode(''),
            'mobile_number' => $order->mobile_number,
            'payment_method' => $order->payment_method,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(Order $order, int $rate): array
    {
        $subtotal = Money::toMinor((float) $order->sub_total, 'USD');
        $vat = Money::toMinor((float) $order->vat, 'USD');
        $fee = Money::toMinor((float) $order->exelo_amount, 'USD');
        $total = Money::toMinor((float) $order->total_price, 'USD');

        return [
            'subtotal' => Money::usd($subtotal),
            'vat' => Money::usd($vat),
            'fee' => Money::usd($fee),
            'total' => Money::usd($total),
            'total_alt' => $this->alt($total, (int) ($order->exchange_rate ?: $rate)),
            'vat_rate' => $subtotal > 0 ? round($vat / $subtotal, 4) : 0,
            'exchange_rate' => (int) ($order->exchange_rate ?: $rate),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestCharge(Order $order): ?array
    {
        $invoice = Invoice::where('order_id', $order->id)->latest('id')->first();

        return $invoice ? ['charge_id' => $invoice->public_id, 'status' => strtolower($invoice->status), 'rail' => $invoice->rail] : null;
    }

    /**
     * @return array{id: ?int, name: ?string}|null
     */
    private function employee(Order $order): ?array
    {
        $user = $order->user;

        if (! $user) {
            return null;
        }

        $employee = $user->employee->exists ? $user->employee : null;

        return [
            'id' => $employee?->id,
            'name' => $employee ? trim($employee->first_name.' '.$employee->last_name) : $user->name,
        ];
    }

    /**
     * @return array{pending_count: int, complete_count: int, pending_value: array<string, mixed>}
     */
    private function summaryCounts(Merchant $merchant): array
    {
        $pending = Order::where('merchant_id', $merchant->id)->tap(fn ($query) => $this->whereStatus($query, 'pending'));
        $complete = Order::where('merchant_id', $merchant->id)->tap(fn ($query) => $this->whereStatus($query, 'complete'));

        return [
            'pending_count' => $pending->count(),
            'complete_count' => $complete->count(),
            'pending_value' => Money::usd(Money::toMinor((float) $pending->sum('total_price'), 'USD')),
        ];
    }

    private function whereStatus($query, string $status): void
    {
        $values = $status === 'complete' ? self::COMPLETE_STATUSES : [$status];
        $query->whereRaw('LOWER(order_status) IN ('.implode(',', array_fill(0, count($values), '?')).')', $values);
    }

    private function restock(Order $order): void
    {
        if (! $order->stock_deducted_at) {
            return;
        }

        foreach ($order->items as $item) {
            $product = Product::withTrashed()->find($item->product_id);

            if ($product) {
                $this->stock->returnToShop($product, $item->quantity);
            }
        }
    }

    private function alt(int $usdCents, int $rate): array
    {
        return Money::of((int) round($usdCents * $rate / 100), config('exelo.alt_currency'));
    }

    private function signatureFile(Order $order): ?File
    {
        return $order->signature_file_id ? File::where('public_id', $order->signature_file_id)->first() : null;
    }

    private function signatureUrl(Order $order): ?string
    {
        return $this->signatureFile($order)?->url();
    }

    /**
     * @return array{file_id: string, url: string}|null
     */
    private function signatureBlock(Order $order): ?array
    {
        $file = $this->signatureFile($order);

        return $file ? ['file_id' => $file->public_id, 'url' => $file->url()] : null;
    }

    private function display($date, Merchant $merchant): ?string
    {
        return $date?->copy()->setTimezone($merchant->timezone)->format('j M Y, H:i');
    }
}
