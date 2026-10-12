<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PaymentSettingsService $paymentSettings,
    ) {}

    /**
     * The till's ticket: one per shop, user, device and type. Created on first use.
     */
    public function current(User $user, Merchant $merchant, string $type = 'shop'): Cart
    {
        return Cart::firstOrCreate([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'device_id' => $this->deviceId($user),
            'cart_type' => $type,
        ], ['version' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, Merchant $merchant, string $type): array
    {
        return $this->snapshot($this->current($user, $merchant, $type), $merchant);
    }

    /**
     * @param  array{product_id: int, quantity?: int, type?: string, unit_price?: array{amount: int, currency: string}, idempotency_key: string}  $data
     * @return array<string, mixed>
     */
    public function addItem(User $user, Merchant $merchant, array $data): array
    {
        $type = $data['type'] ?? 'shop';
        $cart = $this->current($user, $merchant, $type);

        Idempotency::run("cart:{$cart->id}:add", $data['idempotency_key'], $data, fn () => DB::transaction(function () use ($cart, $merchant, $data, $type) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $quantity = $data['quantity'] ?? 1;

            if ($quantity < 1) {
                throw new ApiException('cart.quantity_invalid', 'Quantity must be at least 1', 422, [], 'quantity');
            }

            $product = $this->product($merchant, $data['product_id']);
            $line = $cart->items()->where('product_id', $product->id)->first();
            $wanted = ($line?->quantity ?? 0) + $quantity;

            $this->assertInStock($product, $type, $wanted);

            $this->saveLine($cart, $product, $line, $wanted, $data['unit_price'] ?? null);
            $this->touch($cart);

            return ['data' => null, 'message' => null, 'status' => 200];
        }));

        return $this->snapshot($cart->refresh(), $merchant);
    }

    /**
     * @param  array{quantity?: int, unit_price?: array{amount: int, currency: string}}  $data
     * @return array<string, mixed>
     */
    public function updateItem(User $user, Merchant $merchant, int $productId, string $type, array $data, ?string $ifMatch): array
    {
        $cart = $this->current($user, $merchant, $type);

        DB::transaction(function () use ($cart, $merchant, $productId, $type, $data, $ifMatch) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if ($ifMatch !== null && (int) $ifMatch !== $cart->version) {
                throw new ApiException('cart.version_conflict', 'This ticket was changed on another screen', 409, ['current' => $this->snapshot($cart, $merchant)]);
            }

            $line = $cart->items()->where('product_id', $productId)->first()
                ?? throw new ApiException('cart.line_not_found', 'That item is no longer on the ticket', 404);

            $quantity = $data['quantity'] ?? $line->quantity;
            if ($quantity < 1) {
                throw new ApiException('cart.quantity_invalid', 'Quantity must be at least 1. Remove the item to clear it.', 422, [], 'quantity');
            }

            $product = $this->product($merchant, $productId);

            if ($quantity > $line->quantity) {
                $this->assertInStock($product, $type, $quantity);
            }

            $this->saveLine($cart, $product, $line, $quantity, $data['unit_price'] ?? null);
            $this->touch($cart);
        });

        return $this->snapshot($cart->refresh(), $merchant);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeItem(User $user, Merchant $merchant, int $productId, string $type): array
    {
        $cart = $this->current($user, $merchant, $type);

        DB::transaction(function () use ($cart, $productId) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

            $removed = $cart->items()->where('product_id', $productId)->delete();
            if (! $removed) {
                throw new ApiException('cart.line_not_found', 'That item is no longer on the ticket', 404);
            }

            $this->touch($cart);
        });

        return $this->snapshot($cart->refresh(), $merchant);
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(User $user, Merchant $merchant, string $type): array
    {
        $cart = $this->current($user, $merchant, $type);

        DB::transaction(function () use ($cart) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if ($cart->items()->exists()) {
                $cart->items()->delete();
                $this->touch($cart);
            }
        });

        return $this->snapshot($cart->refresh(), $merchant);
    }

    /**
     * Reconciles a ticket held offline in one call. Every line reports its own outcome.
     *
     * @param  array{type?: string, client_ticket_id: string, idempotency_key: string, strategy?: string, lines: array<int, array<string, mixed>>}  $data
     * @return array{message: string, results: array<int, array<string, mixed>>, cart: array<string, mixed>}
     */
    public function sync(User $user, Merchant $merchant, array $data): array
    {
        $type = $data['type'] ?? 'shop';
        $cart = $this->current($user, $merchant, $type);
        $scope = "cart:{$cart->id}:sync";
        $isReplay = Cache::has('idem:'.$scope.':'.$data['idempotency_key']);

        $result = Idempotency::run($scope, $data['idempotency_key'], $data, fn () => DB::transaction(function () use ($cart, $merchant, $data, $type) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if (($data['strategy'] ?? 'merge') === 'replace') {
                $cart->items()->delete();
            }

            $results = array_map(fn (array $line) => $this->applyHeldLine($cart, $merchant, $type, $line), $data['lines']);
            $this->touch($cart);

            $applied = collect($results)->whereIn('status', ['applied', 'adjusted'])->count();
            $rejected = collect($results)->where('status', 'rejected')->count();

            $message = "$applied held ".Str::plural('line', $applied).' applied';
            if ($rejected > 0) {
                $message .= ", $rejected needs attention";
            }

            return ['data' => $results, 'message' => $message, 'status' => 200];
        }));

        $results = array_map(function (array $row) use ($isReplay) {
            if ($isReplay && in_array($row['status'], ['applied', 'adjusted'], true)) {
                $row['status'] = 'duplicate';
            }

            return $row;
        }, $result['data']);

        return ['message' => $result['message'], 'results' => $results, 'cart' => $this->snapshot($cart->refresh(), $merchant)];
    }

    /**
     * The ticket with priced lines and totals.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Cart $cart, Merchant $merchant): array
    {
        $priced = $this->priced($cart, $merchant);

        return [
            'cart_id' => $cart->id,
            'type' => $cart->cart_type,
            'version' => $cart->version,
            'is_empty' => $priced['items'] === [],
            'items' => $priced['items'],
            'totals' => $priced['totals'],
            'item_count' => count($priced['items']),
            'unit_count' => $priced['units'],
            'updated_at' => ApiResponse::iso($cart->updated_at),
        ];
    }

    /**
     * Lines for an order, priced the same way as the ticket.
     *
     * @return array<int, array{product_id: int, quantity: int, unit_cents: int, unit_amount: int, currency: string, vat_percent: int}>
     */
    public function orderLines(Cart $cart, Merchant $merchant): array
    {
        return $this->priced($cart, $merchant)['lines'];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function applyHeldLine(Cart $cart, Merchant $merchant, string $type, array $line): array
    {
        $result = ['client_line_id' => $line['client_line_id'], 'product_id' => $line['product_id']];
        $quantity = (int) $line['quantity'];

        if ($quantity < 1) {
            return ['status' => 'rejected'] + $result + ['reason' => ['code' => 'cart.quantity_invalid', 'message' => 'Quantity must be at least 1']];
        }

        $product = Product::where('shop_id', $merchant->id)->find($line['product_id']);
        if (! $product) {
            return ['status' => 'rejected'] + $result + ['reason' => ['code' => 'product.not_found', 'message' => 'This product was deleted']];
        }

        $existing = $cart->items()->where('product_id', $product->id)->first();
        $room = $this->stock->quantities($product)[$type] - ($existing?->quantity ?? 0);

        if ($room < 1) {
            return ['status' => 'rejected'] + $result + ['reason' => ['code' => 'product.out_of_stock', 'message' => 'This product is out of stock']];
        }

        $applied = min($quantity, $room);
        $this->saveLine($cart, $product, $existing, ($existing?->quantity ?? 0) + $applied, $line['unit_price'] ?? null);

        if ($applied < $quantity) {
            return ['status' => 'adjusted'] + $result + ['quantity' => $applied, 'reason' => ['code' => 'product.partial_stock', 'message' => "Only $applied left in the shop"]];
        }

        return ['status' => 'applied'] + $result + ['quantity' => $applied];
    }

    private function saveLine(Cart $cart, Product $product, ?CartItem $line, int $quantity, ?array $unitPrice): void
    {
        if ($unitPrice) {
            $stored = $this->storedPrice((int) $unitPrice['amount'], strtoupper($unitPrice['currency']));
        } elseif ($line) {
            $stored = ['price' => $line->price, 'price_currency' => $line->price_currency ?: 'USD'];
        } else {
            $entered = $product->enteredPrice();
            $stored = $this->storedPrice($entered['amount'], $entered['currency']);
        }

        if ($line) {
            $line->update(['quantity' => $quantity] + $stored);
        } else {
            $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity] + $stored);
        }
    }

    /**
     * @return array{price: string, price_currency: string}
     */
    private function storedPrice(int $amount, string $currency): array
    {
        if ($currency === 'SLSH') {
            return ['price' => (string) $amount, 'price_currency' => 'SLSH'];
        }

        return ['price' => (string) ($amount / 100), 'price_currency' => 'USD'];
    }

    private function assertInStock(Product $product, string $type, int $wanted): void
    {
        $available = $this->stock->quantities($product)[$type];

        if ($wanted > $available) {
            throw new ApiException('product.out_of_stock', $available === 0 ? 'This product is out of stock' : "Only $available left", 409, ['available' => $available, 'requested' => $wanted]);
        }
    }

    private function product(Merchant $merchant, int $id): Product
    {
        return Product::where('shop_id', $merchant->id)->find($id)
            ?? throw new ApiException('product.not_found', 'We could not find that product', 404);
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['version' => $cart->version + 1])->save();
    }

    /**
     * Subtotal, GST and total in the price's own currency. GST is applied once
     * to the subtotal and rounded once. The other currency is display only.
     *
     * @return array{items: array<int, array<string, mixed>>, lines: array<int, array<string, mixed>>, totals: array<string, mixed>, units: int}
     */
    private function priced(Cart $cart, Merchant $merchant): array
    {
        $rate = $merchant->effectiveExchangeRate();
        $cart->load(['items.product' => fn ($query) => $query->withTrashed()->with('inventories')]);

        $rows = [];
        foreach ($cart->items->sortBy('id') as $line) {
            $money = $this->lineMoney($line);
            $rows[] = ['line' => $line, 'unit' => $money['amount'], 'currency' => $money['currency']];
        }

        $currencies = collect($rows)->pluck('currency')->unique()->values();
        $currency = $currencies->count() === 1 ? (string) $currencies->first() : 'USD';

        $subtotal = 0;
        $units = 0;
        $items = [];
        $lines = [];

        foreach ($rows as $row) {
            /** @var CartItem $line */
            $line = $row['line'];
            $product = $line->product;
            $lineTotal = $row['currency'] === $currency
                ? $row['unit'] * $line->quantity
                : $this->convertAmount($row['unit'] * $line->quantity, $row['currency'], $currency, $rate);
            $unit = $row['currency'] === $currency ? $row['unit'] : (int) round($lineTotal / max($line->quantity, 1));

            $subtotal += $lineTotal;
            $units += $line->quantity;

            $items[] = [
                'product_id' => $line->product_id,
                'product_name' => $product->product_name,
                'bar_code' => $product->bar_code,
                'quantity' => $line->quantity,
                'unit_price' => Money::of($unit, $currency),
                'unit_price_alt' => $this->converted($unit, $currency, $rate),
                'line_total' => Money::of($lineTotal, $currency),
                'line_total_alt' => $this->converted($lineTotal, $currency, $rate),
                'available_quantity' => $this->stock->quantities($product)[$cart->cart_type],
                'held' => false,
            ];

            $lines[] = [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_amount' => $row['unit'],
                'currency' => $row['currency'],
                'unit_cents' => $row['currency'] === 'SLSH'
                    ? (int) round($row['unit'] / max($rate, 1) * 100)
                    : $row['unit'],
                'vat_percent' => 0,
            ];
        }

        $gst = Money::share($subtotal, $this->paymentSettings->gst()['percent']);
        $total = $subtotal + $gst;

        return [
            'items' => $items,
            'lines' => $lines,
            'units' => $units,
            'totals' => [
                'subtotal' => Money::of($subtotal, $currency),
                'subtotal_alt' => $this->converted($subtotal, $currency, $rate),
                'vat' => Money::of(0, $currency),
                // The wallet fee is added on /payments/quote, not on the ticket.
                'fee' => Money::of(0, $currency),
                'fee_alt' => $this->converted(0, $currency, $rate),
                'fee_percent' => $this->paymentSettings->salesFee()['percent'],
                'gst' => Money::of($gst, $currency),
                'gst_alt' => $this->converted($gst, $currency, $rate),
                'gst_percent' => $this->paymentSettings->gst()['percent'],
                'total' => Money::of($total, $currency),
                'total_alt' => $this->converted($total, $currency, $rate),
                'vat_rate' => 0,
                'exchange_rate' => $rate,
            ],
        ];
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private function lineMoney(CartItem $line): array
    {
        $currency = strtoupper((string) ($line->price_currency ?: 'USD'));

        if ($currency === 'SLSH') {
            return ['amount' => (int) round((float) $line->price), 'currency' => 'SLSH'];
        }

        return ['amount' => Money::toMinor((float) $line->price, 'USD'), 'currency' => 'USD'];
    }

    private function convertAmount(int $amount, string $from, string $to, int $rate): int
    {
        if ($from === $to || $rate < 1) {
            return $amount;
        }

        return $from === 'SLSH'
            ? (int) round($amount / $rate * 100)
            : (int) round($amount * $rate / 100);
    }

    private function converted(int $amount, string $currency, int $rate): array
    {
        if (strtoupper($currency) === 'SLSH') {
            return Money::usd($rate > 0 ? (int) round($amount / $rate * 100) : 0);
        }

        return Money::of($rate > 0 ? (int) round($amount * $rate / 100) : 0, (string) config('exelo.alt_currency'));
    }

    private function deviceId(User $user): string
    {
        $name = (string) $user->currentAccessToken()?->name;

        return Str::startsWith($name, 'device:') ? Str::after($name, 'device:') : 'default';
    }
}
