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
    public function __construct(private readonly StockService $stock) {}

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

            $this->saveLine($cart, $product, $line, $wanted, $merchant, $data['unit_price'] ?? null);
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

            $this->saveLine($cart, $product, $line, $quantity, $merchant, $data['unit_price'] ?? null);
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
        $rate = $merchant->effectiveExchangeRate();
        $cart->load(['items.product' => fn ($query) => $query->withTrashed()->with('inventories')]);

        $subtotal = 0;
        $vat = 0;
        $units = 0;
        $items = [];

        foreach ($cart->items->sortBy('id') as $line) {
            $product = $line->product;
            $unitCents = Money::toMinor((float) $line->price, 'USD');
            $lineCents = $unitCents * $line->quantity;

            $subtotal += $lineCents;
            $vat += (int) round($lineCents * ($product->vat ?? 0) / 100);
            $units += $line->quantity;

            $items[] = [
                'product_id' => $line->product_id,
                'product_name' => $product->product_name,
                'bar_code' => $product->bar_code,
                'quantity' => $line->quantity,
                'unit_price' => Money::usd($unitCents),
                'unit_price_alt' => $this->alt($unitCents, $rate),
                'line_total' => Money::usd($lineCents),
                'line_total_alt' => $this->alt($lineCents, $rate),
                'available_quantity' => $this->stock->quantities($product)[$cart->cart_type],
                'held' => false,
            ];
        }

        $total = $subtotal + $vat;

        return [
            'cart_id' => $cart->id,
            'type' => $cart->cart_type,
            'version' => $cart->version,
            'is_empty' => $items === [],
            'items' => $items,
            'totals' => [
                'subtotal' => Money::usd($subtotal),
                'vat' => Money::usd($vat),
                'fee' => Money::usd(0),
                'total' => Money::usd($total),
                'total_alt' => $this->alt($total, $rate),
                'vat_rate' => $merchant->vat_rate,
                'exchange_rate' => $rate,
            ],
            'item_count' => count($items),
            'unit_count' => $units,
            'updated_at' => ApiResponse::iso($cart->updated_at),
        ];
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

        $product = Product::where('merchant_id', $merchant->id)->find($line['product_id']);
        if (! $product) {
            return ['status' => 'rejected'] + $result + ['reason' => ['code' => 'product.not_found', 'message' => 'This product was deleted']];
        }

        $existing = $cart->items()->where('product_id', $product->id)->first();
        $room = $this->stock->quantities($product)[$type] - ($existing?->quantity ?? 0);

        if ($room < 1) {
            return ['status' => 'rejected'] + $result + ['reason' => ['code' => 'product.out_of_stock', 'message' => 'This product is out of stock']];
        }

        $applied = min($quantity, $room);
        $this->saveLine($cart, $product, $existing, ($existing?->quantity ?? 0) + $applied, $merchant, $line['unit_price'] ?? null);

        if ($applied < $quantity) {
            return ['status' => 'adjusted'] + $result + ['quantity' => $applied, 'reason' => ['code' => 'product.partial_stock', 'message' => "Only $applied left in the shop"]];
        }

        return ['status' => 'applied'] + $result + ['quantity' => $applied];
    }

    private function saveLine(Cart $cart, Product $product, ?CartItem $line, int $quantity, Merchant $merchant, ?array $unitPrice): void
    {
        $price = $line?->price ?? $product->price;

        if ($unitPrice) {
            $price = $unitPrice['currency'] === 'USD'
                ? $unitPrice['amount'] / 100
                : round($unitPrice['amount'] / $merchant->effectiveExchangeRate(), 2);
        }

        if ($line) {
            $line->update(['quantity' => $quantity, 'price' => $price]);
        } else {
            $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'price' => $price]);
        }
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
        return Product::where('merchant_id', $merchant->id)->find($id)
            ?? throw new ApiException('product.not_found', 'We could not find that product', 404);
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['version' => $cart->version + 1])->save();
    }

    private function alt(int $usdCents, int $rate): array
    {
        return Money::of((int) round($usdCents * $rate / 100), config('exelo.alt_currency'));
    }

    private function deviceId(User $user): string
    {
        $name = (string) $user->currentAccessToken()?->name;

        return Str::startsWith($name, 'device:') ? Str::after($name, 'device:') : 'default';
    }
}
