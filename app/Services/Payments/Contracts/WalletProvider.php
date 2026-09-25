<?php

namespace App\Services\Payments\Contracts;

use App\Models\Invoice;

/**
 * One mobile-wallet provider. Every method makes a single call; callers poll
 * rather than the provider looping.
 */
interface WalletProvider
{
    /**
     * Ask the customer's wallet to pay. `prompt` is "declined" when the provider
     * reports that the customer turned the prompt down; the payment stays pending.
     *
     * @param  string  $description  Shown to the customer where the provider supports it
     * @return array{invoice_id: string, transaction_id: string, hash: string, prompt: ?string}
     */
    public function issue(string $walletE164, int $amount, string $currency, string $description): array;

    /**
     * Where a pending payment stands now.
     *
     * @return array{status: string, provider_transaction_id: ?string, reason: ?string}
     */
    public function status(Invoice $invoice): array;
}
