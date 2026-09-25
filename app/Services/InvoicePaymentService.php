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
            'e_transaction_id' => $providerTransactionId ?? $invoice->e_transaction_id,
        ]);

        InvoicePaid::dispatch($invoice);

        return $invoice;
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
            ] + ($invoice->isPromptDeclined() ? ['prompt' => 'declined'] : []);
        } elseif ($status === 'paid') {
            $payload['paid_at'] = ApiResponse::iso($invoice->paid_at);

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
