<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\Payments\Contracts\WalletProvider;
use App\Services\Payments\Providers\EdahabProvider;
use App\Services\Payments\Providers\WaafiProvider;

/**
 * Routes wallet payments to the eDahab or WaafiPay (Zaad) provider by rail.
 * Every method makes a single call; callers poll rather than the gateway looping.
 */
class WalletGateway
{
    public function __construct(
        private readonly EdahabProvider $edahab,
        private readonly WaafiProvider $waafi,
    ) {}

    /**
     * @param  string  $description  What the customer is paying for, shown on the Zaad prompt
     * @return array{invoice_id: string, transaction_id: string, hash: string, prompt: ?string}
     */
    public function issue(string $rail, string $walletE164, int $amount, string $currency, string $description = 'EXELO payment'): array
    {
        return $this->provider($rail)->issue($walletE164, $amount, $currency, $description);
    }

    /**
     * @return array{status: string, provider_transaction_id: ?string, reason: ?string}
     */
    public function status(Invoice $invoice): array
    {
        return $this->provider($invoice->rail)->status($invoice);
    }

    private function provider(string $rail): WalletProvider
    {
        return $rail === 'edahab' ? $this->edahab : $this->waafi;
    }
}
