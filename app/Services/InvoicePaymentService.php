<?php

namespace App\Services;

use App\Events\InvoicePaid;
use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\Money;

/**
 * The one place an invoice moves from Pending to a final state. Wallet rails are
 * checked with the provider; cash is confirmed by staff.
 */
class InvoicePaymentService
{
    public function __construct(private readonly WalletGateway $gateway) {}

    /**
     * Single provider check for a pending invoice; callers poll rather than loop.
     */
    public function refresh(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'Pending') {
            return $invoice;
        }

        if ($invoice->rail === 'cash') {
            if ($invoice->expires_at?->isPast()) {
                $invoice->update(['status' => 'Expired', 'error_reason' => 'Cash payment was not confirmed in time']);
            }

            return $invoice;
        }

        $result = $this->gateway->status($invoice);

        if ($result['status'] === 'Paid') {
            return $this->markPaid($invoice, $result['provider_transaction_id']);
        }

        if ($result['status'] === 'Pending' && $invoice->expires_at?->isFuture()) {
            return $invoice;
        }

        $invoice->update([
            'status' => $result['status'] === 'Pending' ? 'Expired' : $result['status'],
            'error_reason' => $result['reason'],
        ]);

        return $invoice;
    }

    public function markPaid(Invoice $invoice, ?string $providerTransactionId = null): Invoice
    {
        $invoice->update([
            'status' => 'Paid',
            'paid_at' => now(),
            'e_transaction_id' => $invoice->e_transaction_id ?: $providerTransactionId,
        ]);

        InvoicePaid::dispatch($invoice);

        return $invoice;
    }

    /**
     * The app received the payer's eDahab SMS (Code + Txn Id). Store it, then
     * ask eDahab whether the invoice is actually Paid — never settle on the SMS alone.
     *
     * @param  array{provider_transaction_id: string, confirmation_code?: ?string, message?: ?string, amount?: mixed}  $data
     */
    public function confirm(Invoice $invoice, array $data): Invoice
    {
        if ($invoice->rail !== 'edahab') {
            throw new ApiException('payment.rail_unavailable', 'This payment does not take an eDahab confirmation', 422);
        }

        $txnId = $data['provider_transaction_id'];

        if ($invoice->status !== 'Pending' && $invoice->status !== 'Paid') {
            throw new ApiException('payment.already_settled', 'This payment is already finished', 409);
        }

        $confirmation = array_filter([
            'code' => $data['confirmation_code'] ?? null,
            'provider_transaction_id' => $txnId,
            'message' => $data['message'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'received_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);

        $invoice->update([
            'meta' => array_merge($invoice->meta ?? [], ['edahab_confirmation' => $confirmation]),
            'e_transaction_id' => $invoice->e_transaction_id ?: $txnId,
        ]);

        if ($invoice->status === 'Paid') {
            return $invoice->fresh();
        }

        try {
            return $this->refresh($invoice->fresh());
        } catch (ApiException $e) {
            if ($e->errorCode !== 'payment.provider_unavailable') {
                throw $e;
            }

            return $invoice->fresh();
        }
    }

    /**
     * eDahab redirected the payer to ReturnUrl. Do not trust the query string:
     * look the invoice up and ask eDahab whether it is Paid.
     */
    public function returnFromEdahab(array $query): Invoice
    {
        $providerInvoiceId = $query['invoiceId'] ?? $query['InvoiceId'] ?? $query['invoice_id'] ?? null;

        if (! is_string($providerInvoiceId) || $providerInvoiceId === '') {
            throw new ApiException('invoice.not_found', 'We could not find that payment', 404);
        }

        $invoice = Invoice::where('rail', 'edahab')->where('invoice_id', $providerInvoiceId)->first();

        if (! $invoice) {
            throw new ApiException('invoice.not_found', 'We could not find that payment', 404);
        }

        return $this->refresh($invoice);
    }

    /**
     * Staff confirm they received the cash for a pending cash invoice.
     */
    public function confirmCash(Invoice $invoice): Invoice
    {
        if ($invoice->rail !== 'cash') {
            throw new ApiException('invoice.not_cash', 'This is not a cash payment', 409);
        }

        if ($invoice->status !== 'Pending') {
            throw new ApiException('invoice.not_pending', 'Only a pending payment can be confirmed', 409);
        }

        return $this->markPaid($invoice, 'CASH-CONFIRMED');
    }

    /**
     * Local testing only; the caller has already checked the environment guard.
     */
    public function simulate(Invoice $invoice, string $outcome): Invoice
    {
        if ($invoice->status !== 'Pending') {
            throw new ApiException('invoice.not_pending', 'Only a pending invoice can be simulated', 409);
        }

        if ($outcome === 'paid') {
            return $this->markPaid($invoice, 'SIMULATED');
        }

        $invoice->update(['status' => ucfirst($outcome), 'error_reason' => 'Simulated '.$outcome]);

        return $invoice;
    }

    /**
     * Charge-shaped view of an invoice, as `docs/payments.md` describes it.
     */
    public function chargePayload(Invoice $invoice): array
    {
        $status = strtolower($invoice->status);

        $isSale = $invoice->type === 'Sale';

        $payload = [
            'charge_id' => $invoice->public_id,
            'shop' => ['id' => $invoice->merchant_id, 'business_name' => $invoice->merchant?->business_name],
            'status' => $status,
            'rail' => $invoice->rail,
            'purpose' => $invoice->purpose ?? strtolower($invoice->type),
            'amount' => $invoice->currency === 'USD'
                ? Money::usd(Money::toMinor((float) $invoice->amount, 'USD'))
                : Money::format((int) $invoice->amount, $invoice->currency),
            'customer' => ['wallet_number' => $invoice->wallet_number] + ($isSale ? ['name' => $invoice->meta['customer']['name'] ?? null] : []),
            'created_at' => ApiResponse::iso($invoice->created_at),
        ];

        if ($status === 'pending') {
            $payload += [
                'next_action' => $invoice->rail === 'cash' ? 'await_cash_confirmation' : 'await_customer_approval',
                'poll_after' => config('exelo.registration.poll_after_seconds'),
                'expires_at' => ApiResponse::iso($invoice->expires_at),
            ] + ($invoice->isPromptDeclined() ? ['prompt' => 'declined'] : [])
            + ($invoice->rail === 'edahab' && $invoice->invoice_id
                ? ['payment_url' => 'https://edahab.net/api/payment?invoiceId='.$invoice->invoice_id]
                : []);
        } elseif ($status === 'paid') {
            $payload['paid_at'] = ApiResponse::iso($invoice->paid_at);
            $payload['provider_transaction_id'] = $invoice->e_transaction_id;
            $payload['confirmation_code'] = $invoice->meta['edahab_confirmation']['code'] ?? null;

            if ($isSale && $invoice->order_id) {
                $order = Order::find($invoice->order_id);
                $payload['order'] = ['id' => $order->id, 'order_status' => 'Complete'];
                $payload['receipt'] = ['invoice_no' => 'INV-'.$order->id, 'url' => '/api/v1/orders/'.$order->id.'/receipt'];
            }
        } else {
            $payload['failure'] = ['code' => $status, 'message' => $invoice->error_reason];
        }

        return $payload;
    }
}
