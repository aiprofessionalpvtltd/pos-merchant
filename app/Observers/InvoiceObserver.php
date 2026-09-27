<?php

namespace App\Observers;

use App\Models\ApiLog;
use App\Models\Invoice;
use App\Models\Transaction;

class InvoiceObserver
{
    private const TERMINAL_STATUSES = ['Paid', 'Failed', 'Expired', 'Cancelled', 'Declined'];

    /**
     * The provider call that issued a wallet invoice runs before the invoice row exists,
     * so link its api_logs row now, by the reference we sent: eDahab keeps it as the
     * transaction_id, WaafiPay's referenceId is kept as the invoice_id.
     */
    public function created(Invoice $invoice): void
    {
        $reference = match ($invoice->rail) {
            'edahab' => $invoice->transaction_id,
            'zaad' => $invoice->invoice_id,
            default => null,
        };

        if (! $reference) {
            return;
        }

        ApiLog::whereNull('invoice_id')
            ->where('provider', $invoice->rail === 'zaad' ? 'waafi' : 'edahab')
            ->where('our_reference', $reference)
            ->where('created_at', '>=', now()->subHour())
            ->update(['invoice_id' => $invoice->id]);
    }

    /**
     * Keeps the legacy `transactions` table a complete log of every payment v1
     * settles — registration, verification, subscription and sales/orders alike,
     * not just the ones the legacy API itself creates. One row per invoice,
     * written once it leaves `Pending` (see docs/data-model.md).
     */
    public function updated(Invoice $invoice): void
    {
        if (! in_array($invoice->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        // A `pos_sale` charge links its order (`order_id`) only after it settles,
        // in a second update right after this one turns the invoice Paid — so a
        // transaction is (re)synced on either change, not just the status change.
        if (! $invoice->wasChanged('status') && ! $invoice->wasChanged('order_id')) {
            return;
        }

        Transaction::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'merchant_id' => $invoice->merchant_id,
                'order_id' => $invoice->order_id,
                'transaction_amount' => (string) $this->shillings($invoice),
                'transaction_status' => $invoice->status,
                'transaction_message' => $invoice->status === 'Paid'
                    ? (($invoice->type ?: 'Payment').' payment received')
                    : ($invoice->error_reason ?: $invoice->status),
                'phone_number' => (string) ($invoice->wallet_number ?: $invoice->mobile_number ?: ''),
                'transaction_id' => (string) ($invoice->e_transaction_id ?: $invoice->transaction_id ?: 'N/A'),
                'payment_method' => (string) ($invoice->rail ?: $invoice->payment_method ?: 'number'),
            ]
        );
    }

    /**
     * The legacy `transactions.transaction_amount` column is always shillings
     * (existing rows are summed and shown that way in the admin dashboard), so a
     * USD invoice is converted at the rate the sale actually settled at: its
     * order's frozen rate when it has one, else the shop's current rate.
     */
    private function shillings(Invoice $invoice): float
    {
        if (strtoupper((string) $invoice->currency) === 'SLSH') {
            return (float) $invoice->amount;
        }

        $rate = (int) ($invoice->order->exchange_rate ?: $invoice->merchant->effectiveExchangeRate() ?: config('exelo.conversion_rate'));

        return round((float) $invoice->amount * $rate, 2);
    }
}
