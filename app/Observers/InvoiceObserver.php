<?php

namespace App\Observers;

use App\Models\ApiLog;
use App\Models\Invoice;

class InvoiceObserver
{
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
}
