<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use Illuminate\Support\Facades\Cache;
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
        private readonly FileService $files,
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

            $total = $snapshot['totals']['total'];
            $amount = ['amount' => $total['amount'], 'currency' => $total['currency']];

            if (! empty($data['quote_id'])) {
                $stored = Cache::get('payment-quote:'.$data['quote_id']);
                $quoted = is_array($stored) ? ($stored['quote']['amount'] ?? null) : null;

                if (is_array($quoted) && isset($quoted['amount'], $quoted['currency'])) {
                    $amount = ['amount' => (int) $quoted['amount'], 'currency' => strtoupper((string) $quoted['currency'])];
                }
            }

            $charge = $this->charges->create($user, $merchant, [
                'rail' => $data['rail'],
                'purpose' => 'pos_sale',
                'amount' => $amount,
                'customer' => $data['customer'] ?? [],
                'cart_id' => $cart->id,
                'cart_version' => $cart->version,
                'quote_id' => $data['quote_id'] ?? null,
                'idempotency_key' => 'pay:'.$data['idempotency_key'],
                'tendered' => $data['amount_tendered'] ?? null,
            ]);

            $payload = $charge['data'];

            if ($charge['status'] === 202) {
                return ['data' => [
                    'status' => 'pending', 'charge_id' => $payload['charge_id'], 'poll' => $payload['poll'], 'poll_after' => $payload['poll_after'],
                ], 'message' => $charge['message'], 'status' => 202];
            }

            $order = Order::findOrFail($payload['order']['id']);

            return ['data' => [
                'status' => 'paid',
                'charge_id' => $payload['charge_id'],
                'order' => ['id' => $order->id, 'order_status' => 'Complete'] + $this->orders->totalBlock($order, $merchant),
                'change_due' => $payload['change_due'] ?? null,
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

            $lines = $this->carts->orderLines($cart, $merchant);

            $signatureFileId = $data['signature_file_id'] ?? null;

            if ($signatureFileId) {
                $signature = $this->files->find($merchant, $signatureFileId);

                if ($signature->purpose !== 'signature') {
                    throw new ApiException('validation.failed', 'Please check the form', 422, ['signature_file_id' => ['That file was not uploaded as a signature']], 'signature_file_id');
                }
            }

            $order = $this->orders->createFromLines($user, $merchant, $lines, $data['customer'], [
                'order_status' => 'Pending',
                'order_type' => $type,
                'note' => $data['note'] ?? null,
                'signature_file_id' => $signatureFileId,
            ]);

            $this->files->markAttached($signatureFileId, $merchant);

            $cart->items()->delete();
            $cart->forceFill(['version' => $cart->version + 1])->save();

            return ['data' => [
                'order' => [
                    'id' => $order->id,
                    'order_status' => 'Pending',
                    'name' => $order->name,
                    'mobile_number' => $order->mobile_number,
                    'created_at' => ApiResponse::iso($order->created_at),
                ] + $this->orders->totalBlock($order, $merchant),
                'cart' => $this->carts->snapshot($cart->refresh(), $merchant),
            ], 'message' => 'Order held for '.$order->name, 'status' => 201];
        }));
    }
}
