<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The register's two ways of closing a ticket: take payment now, or park it as a pending order.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly ChargeService $charges,
        private readonly OrderService $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated /cart/pay body
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function pay(User $user, Merchant $merchant, array $data): array
    {
        $type = $data['type'] ?? 'shop';
        $cart = $this->carts->current($user, $merchant, $type);

        return Idempotency::run("cart:{$cart->id}:pay", $data['idempotency_key'], $data, function () use ($user, $merchant, $data, $type) {
            $cart = $this->carts->current($user, $merchant, $type);

            if ($cart->version !== (int) $data['cart_version']) {
                throw new ApiException('cart.version_conflict', 'The ticket changed. Check the total and try again.', 409, ['current' => $this->carts->snapshot($cart, $merchant)]);
            }

            $snapshot = $this->carts->snapshot($cart, $merchant);

            if ($snapshot['is_empty']) {
                throw new ApiException('cart.empty', 'There is nothing to pay for', 422);
            }

            $total = $snapshot['totals']['total']['amount'];

            $charge = $this->charges->create($user, $merchant, [
                'rail' => $data['rail'],
                'purpose' => 'pos_sale',
                'amount' => ['amount' => $total, 'currency' => 'USD'],
                'customer' => $data['customer'] ?? [],
                'cart_id' => $cart->id,
                'cart_version' => $cart->version,
                'quote_id' => $data['quote_id'] ?? null,
                'idempotency_key' => 'pay:'.$data['idempotency_key'],
                'tendered' => $data['amount_tendered']['amount'] ?? null,
            ]);

            $payload = $charge['data'];

            if ($charge['status'] === 202) {
                return ['data' => [
                    'status' => 'pending', 'charge_id' => $payload['charge_id'], 'poll' => $payload['poll'], 'poll_after' => $payload['poll_after'],
                ], 'message' => $charge['message'], 'status' => 202];
            }

            $order = Order::findOrFail($payload['order']['id']);
            $changeDue = isset($data['amount_tendered']) ? Money::usd($data['amount_tendered']['amount'] - $payload['customer_charge']['amount']) : null;

            return ['data' => [
                'status' => 'paid',
                'charge_id' => $payload['charge_id'],
                'order' => ['id' => $order->id, 'order_status' => 'Complete', 'total' => Money::usd(Money::toMinor((float) $order->total_price, 'USD'))],
                'change_due' => $changeDue,
                'receipt' => $payload['receipt'],
                'cart' => $this->carts->snapshot($cart->refresh(), $merchant),
            ], 'message' => 'Sale complete', 'status' => 200];
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated /cart/hold body
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function hold(User $user, Merchant $merchant, array $data): array
    {
        $type = $data['type'] ?? 'shop';
        $cart = $this->carts->current($user, $merchant, $type);

        return Idempotency::run("cart:{$cart->id}:hold", $data['idempotency_key'], $data, fn () => DB::transaction(function () use ($user, $merchant, $data, $cart, $type) {
            $cart = $cart->newQuery()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $cart->load(['items.product' => fn ($query) => $query->withTrashed()]);

            if ($cart->items->isEmpty()) {
                throw new ApiException('cart.empty', 'There is nothing to hold', 422);
            }

            $lines = $cart->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_cents' => Money::toMinor((float) $item->price, 'USD'),
                'vat_percent' => (int) ($item->product->vat ?? 0),
            ])->all();

            $order = $this->orders->createFromLines($user, $merchant, $lines, $data['customer'], [
                'order_status' => 'Pending',
                'order_type' => $type,
                'note' => $data['note'] ?? null,
            ]);

            $cart->items()->delete();
            $cart->forceFill(['version' => $cart->version + 1])->save();

            return ['data' => [
                'order' => [
                    'id' => $order->id,
                    'order_status' => 'Pending',
                    'name' => $order->name,
                    'mobile_number' => $order->mobile_number,
                    'total' => Money::usd(Money::toMinor((float) $order->total_price, 'USD')),
                    'created_at' => ApiResponse::iso($order->created_at),
                ],
                'cart' => $this->carts->snapshot($cart->refresh(), $merchant),
            ], 'message' => 'Order held for '.$order->name, 'status' => 201];
        }));
    }
}
