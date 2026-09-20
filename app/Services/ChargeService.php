<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Support\Idempotency;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sale charges: taking money for a ticket (pos_sale) or for a held order (order_settlement).
 * A charge is an invoices row of type Sale; the order appears when it is paid.
 */
class ChargeService
{
    private const WALLET_RAILS = ['zaad', 'edahab'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly InvoicePaymentService $invoices,
        private readonly WalletGateway $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated charge request
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function create(User $actor, Merchant $merchant, array $data): array
    {
        return Idempotency::run("m{$merchant->id}:charge", $data['idempotency_key'], $data, function () use ($actor, $merchant, $data) {
            $rail = $data['rail'];

            if (! in_array($rail, $this->payments->acceptedRails($merchant), true) || ! in_array($rail, ['cash', ...self::WALLET_RAILS], true)) {
                throw new ApiException('payment.rail_unavailable', 'That payment method is not available for this shop', 422, ['available_rails' => array_values(array_intersect($this->payments->acceptedRails($merchant), ['cash', ...self::WALLET_RAILS]))], 'rail');
            }

            $sale = $data['purpose'] === 'pos_sale'
                ? $this->saleFromCart($actor, $merchant, $data)
                : $this->saleFromOrder($merchant, $data);

            $this->assertAmount($sale['total_cents'], $data['amount']);
            $fee = $this->fee($merchant, $data, $rail, $sale['total_cents']);
            $chargeCents = $sale['total_cents'] + ($fee['payer'] === 'customer' ? $fee['amount'] : 0);

            $this->assertNoOpenCharge($sale);

            if ($rail === 'cash' && isset($data['tendered']) && $data['tendered'] < $chargeCents) {
                throw new ApiException('payment.tender_too_low', 'The cash given is less than the total', 422, ['due' => Money::usd($chargeCents)], 'amount_tendered');
            }

            $meta = [
                'lines' => $sale['lines'],
                'customer' => ['name' => $data['customer']['name'] ?? null, 'mobile_number' => $data['customer']['mobile_number'] ?? null],
                'cart_type' => $sale['cart_type'],
                'order_id' => $sale['order_id'],
                'total_cents' => $sale['total_cents'],
                'fee_cents' => $fee['amount'],
                'fee_payer' => $fee['payer'],
                'customer_charge_cents' => $chargeCents,
            ];

            return $rail === 'cash'
                ? $this->chargeCash($actor, $merchant, $data, $sale, $meta, $chargeCents)
                : $this->chargeWallet($actor, $merchant, $data, $sale, $meta, $chargeCents);
        });
    }

    /**
     * An invoice paid: turn a Sale charge into an order (once) and clear the ticket.
     */
    public function finalize(Invoice $invoice): void
    {
        if ($invoice->type !== 'Sale') {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $meta = $invoice->meta ?? [];
            $merchant = Merchant::findOrFail($invoice->merchant_id);
            $actor = User::findOrFail($invoice->user_id);

            if ($invoice->purpose === 'order_settlement') {
                $order = Order::with('items')->lockForUpdate()->find($meta['order_id']);

                if ($order && ! $order->paid_at) {
                    $this->orders->settle($order, $invoice->rail, $actor);
                }

                return;
            }

            if ($invoice->order_id) {
                return;
            }

            $order = $this->orders->createFromLines($actor, $merchant, $meta['lines'], $meta['customer'], [
                'order_status' => 'Complete',
                'order_type' => $meta['cart_type'],
            ], $meta['fee_cents']);
            $order->load('items');

            $this->orders->settle($order, $invoice->rail, $actor);
            $invoice->update(['order_id' => $order->id]);

            $this->clearCart($invoice);
        });
    }

    /**
     * @return array{lines: array<int, array<string, int>>, total_cents: int, cart_type: string, order_id: ?int, cart_id: ?int, cart_version: ?int, source: string}
     */
    private function saleFromCart(User $actor, Merchant $merchant, array $data): array
    {
        $cart = Cart::where('merchant_id', $merchant->id)->where('user_id', $actor->id)->find($data['cart_id'] ?? 0)
            ?? throw new ApiException('cart.not_found', 'We could not find that ticket', 404);

        $cart->load(['items.product' => fn ($query) => $query->withTrashed()]);

        if ($cart->items->isEmpty()) {
            throw new ApiException('cart.empty', 'There is nothing to pay for', 422);
        }

        if (isset($data['cart_version']) && (int) $data['cart_version'] !== $cart->version) {
            throw new ApiException('payment.cart_changed', 'The ticket changed. Check the total and try again.', 409, ['current' => $this->carts->snapshot($cart, $merchant)]);
        }

        $lines = [];
        $total = 0;

        foreach ($cart->items as $item) {
            $unit = Money::toMinor((float) $item->price, 'USD');
            $vatPercent = (int) ($item->product->vat ?? 0);
            $lines[] = ['product_id' => $item->product_id, 'quantity' => $item->quantity, 'unit_cents' => $unit, 'vat_percent' => $vatPercent];
            $total += $unit * $item->quantity + (int) round($unit * $item->quantity * $vatPercent / 100);
        }

        return ['lines' => $lines, 'total_cents' => $total, 'cart_type' => $cart->cart_type, 'order_id' => null, 'cart_id' => $cart->id, 'cart_version' => $cart->version, 'source' => "cart:{$cart->id}"];
    }

    /**
     * @return array{lines: array<int, array<string, int>>, total_cents: int, cart_type: string, order_id: ?int, cart_id: ?int, cart_version: ?int, source: string}
     */
    private function saleFromOrder(Merchant $merchant, array $data): array
    {
        $order = Order::where('merchant_id', $merchant->id)->with('items')->find($data['order_id'] ?? 0)
            ?? throw new ApiException('order.not_found', 'We could not find that order', 404);

        if ($order->paid_at) {
            throw new ApiException('order.already_paid', 'This order is already paid', 409);
        }

        if (! $order->isPending()) {
            throw new ApiException('order.invalid_transition', 'Only a pending order can be paid', 409, ['from' => strtolower($order->order_status), 'allowed' => []]);
        }

        return [
            'lines' => [], 'total_cents' => Money::toMinor((float) $order->total_price, 'USD'), 'cart_type' => $order->order_type ?? 'shop',
            'order_id' => $order->id, 'cart_id' => null, 'cart_version' => null, 'source' => "order:{$order->id}",
        ];
    }

    private function assertAmount(int $totalCents, array $amount): void
    {
        if ($amount['currency'] !== 'USD' || (int) $amount['amount'] !== $totalCents) {
            throw new ApiException('payment.cart_changed', 'The amount does not match the sale total', 409, ['current_total' => Money::usd($totalCents)]);
        }
    }

    /**
     * @return array{amount: int, payer: string}
     */
    private function fee(Merchant $merchant, array $data, string $rail, int $totalCents): array
    {
        if (isset($data['quote_id'])) {
            $stored = Cache::get('payment-quote:'.$data['quote_id']);

            if (! $stored) {
                throw new ApiException('quote.expired', 'That quote has expired. Request a new one.', 410);
            }

            if ($stored['merchant_id'] !== $merchant->id || $stored['rail'] !== $rail || $stored['purpose'] !== $data['purpose'] || $stored['quote']['amount']['amount'] !== $totalCents) {
                throw new ApiException('validation.failed', 'Please check the form', 422, ['quote_id' => ['This quote is for a different payment']], 'quote_id');
            }

            return ['amount' => $stored['quote']['fees']['platform']['amount'], 'payer' => $stored['quote']['fee_payer'] ?? 'merchant'];
        }

        return $this->payments->fee($merchant, $rail, $totalCents);
    }

    private function assertNoOpenCharge(array $sale): void
    {
        $column = $sale['cart_id'] ? 'cart_id' : 'order_id';
        $value = $sale['cart_id'] ?? $sale['order_id'];

        $open = Invoice::where('type', 'Sale')->where('status', 'Pending')->where($column, $value)->where('expires_at', '>', now())->first();

        if ($open) {
            throw new ApiException('payment.charge_pending', 'A payment for this sale is already waiting for the customer', 409, ['charge_id' => $open->public_id]);
        }
    }

    /**
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    private function chargeCash(User $actor, Merchant $merchant, array $data, array $sale, array $meta, int $chargeCents): array
    {
        return DB::transaction(function () use ($actor, $merchant, $data, $sale, $meta, $chargeCents) {
            $invoice = $this->record($actor, $merchant, $data, $sale, $meta, [
                'invoice_id' => 'CASH', 'transaction_id' => 'CASH', 'hash' => '0', 'mobile_number' => $merchant->phone_number,
                'amount' => $chargeCents / 100, 'currency' => 'USD', 'expires_at' => now()->addMinutes(10),
            ]);

            $invoice = $this->invoices->markPaid($invoice, 'CASH');

            return ['data' => $this->payload($invoice->refresh(), $merchant), 'message' => 'Sale complete', 'status' => 200];
        });
    }

    /**
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    private function chargeWallet(User $actor, Merchant $merchant, array $data, array $sale, array $meta, int $chargeCents): array
    {
        $rail = $data['rail'];
        $wallet = $data['customer']['wallet_number'] ?? null;

        if (! $wallet) {
            throw new ApiException('validation.failed', 'Please check the form', 422, ['customer.wallet_number' => ['Enter the customer\'s wallet number']], 'customer.wallet_number');
        }

        $wallet = PhoneNumber::normalize($wallet);

        if (PhoneNumber::carrier($wallet) !== $rail) {
            throw new ApiException('payment.wallet_invalid', 'That wallet number does not belong to '.ucfirst($rail), 422, [], 'customer.wallet_number');
        }

        $shillings = (int) round($chargeCents * $merchant->effectiveExchangeRate() / 100);
        $issued = $this->gateway->issue($rail, $wallet, $shillings, config('exelo.alt_currency'));

        $invoice = $this->record($actor, $merchant, $data, $sale, $meta, [
            'invoice_id' => $issued['invoice_id'], 'transaction_id' => $issued['transaction_id'], 'hash' => $issued['hash'],
            'mobile_number' => $wallet, 'wallet_number' => $wallet, 'amount' => $shillings, 'currency' => config('exelo.alt_currency'),
            'expires_at' => now()->addSeconds(config('exelo.registration.invoice_ttl_seconds')),
        ]);

        return ['data' => $this->payload($invoice, $merchant) + ['poll' => '/api/v1/payments/charges/'.$invoice->public_id], 'message' => 'Ask the customer to approve the payment', 'status' => 202];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(User $actor, Merchant $merchant, array $data, array $sale, array $meta, array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'merchant_id' => $merchant->id,
            'user_id' => $actor->id,
            'order_id' => $sale['order_id'],
            'public_id' => 'chg_'.Str::upper(Str::ulid()->toBase32()),
            'rail' => $data['rail'],
            'payment_method' => $data['rail'],
            'status' => 'Pending',
            'type' => 'Sale',
            'purpose' => $data['purpose'],
            'cart_id' => $sale['cart_id'],
            'cart_version' => $sale['cart_version'],
            'first_name' => $data['customer']['name'] ?? null,
            'meta' => $meta,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice, Merchant $merchant): array
    {
        return $this->invoices->chargePayload($invoice) + ['customer_charge' => Money::usd($invoice->meta['customer_charge_cents'])];
    }

    private function clearCart(Invoice $invoice): void
    {
        $cart = Cart::whereKey($invoice->cart_id)->lockForUpdate()->first();

        if ($cart && $cart->version === $invoice->cart_version) {
            $cart->items()->delete();
            $cart->forceFill(['version' => $cart->version + 1])->save();
        }
    }
}
