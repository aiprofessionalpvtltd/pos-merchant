<?php

namespace App\Services\Payments\Providers;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Services\Payments\Contracts\WalletProvider;
use App\Services\Payments\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * WaafiPay (Zaad). A payment is held with API_PREAUTHORIZE and taken with API_PREAUTHORIZE_COMMIT. See docs/waafipay.md.
 */
class WaafiProvider implements WalletProvider
{
    public function __construct(private readonly ProviderHttp $http) {}

    public function issue(string $walletE164, int $amount, string $currency, string $description): array
    {
        $referenceId = (string) random_int(100000, 999999);

        $body = $this->post('API_PREAUTHORIZE', ['our_reference' => $referenceId], [
            'paymentMethod' => 'MWALLET_ACCOUNT',
            'payerInfo' => ['accountNo' => substr($walletE164, 1)],
            'transactionInfo' => [
                'referenceId' => $referenceId,
                'invoiceId' => (string) random_int(100000, 999999),
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'paymentBrand' => 'WAAFI',
            ],
        ]);

        if (! $this->isSuccess($body)) {
            throw new ApiException('payment.wallet_invalid', $body['responseMsg'] ?? 'That wallet number is not valid', 422, [], 'wallet_number');
        }

        return [
            'invoice_id' => (string) $body['params']['referenceId'],
            'transaction_id' => (string) $body['params']['transactionId'],
            'hash' => '0',
            'prompt' => null,
        ];
    }

    /**
     * Zaad has no status call: the commit is the check, and it takes the money once the customer has approved.
     */
    public function status(Invoice $invoice): array
    {
        $body = $this->post('API_PREAUTHORIZE_COMMIT', ['invoice_id' => $invoice->id, 'our_reference' => $invoice->invoice_id], [
            'transactionId' => $invoice->transaction_id,
            'referenceId' => $invoice->invoice_id,
            'description' => 'Commit transaction',
        ]);

        $isApproved = $this->isSuccess($body) && strtolower((string) ($body['params']['state'] ?? '')) === 'approved';

        return [
            'status' => $isApproved ? 'Paid' : 'Pending',
            'provider_transaction_id' => $isApproved ? ($body['params']['transactionId'] ?? null) : null,
            'reason' => null,
        ];
    }

    /**
     * WaafiPay reports success as errorCode "0"; failures are codes like "E10235". A missing code is not a success.
     */
    private function isSuccess(array $body): bool
    {
        return (string) ($body['errorCode'] ?? '') === '0';
    }

    /**
     * @param  array{invoice_id?: ?int, our_reference?: ?string}  $context
     */
    private function post(string $serviceName, array $context, array $serviceParams): array
    {
        $config = config('exelo.providers.waafi');

        $payload = [
            'schemaVersion' => '1.0',
            'requestId' => uniqid('', true),
            'timestamp' => now()->format('Y-m-d'),
            'channelName' => 'WEB',
            'serviceName' => $serviceName,
            'serviceParams' => [
                'merchantUid' => $config['merchant_uid'],
                'apiUserId' => $config['api_user_id'],
                'apiKey' => $config['api_key'],
            ] + $serviceParams,
        ];

        return $this->http->send(
            'waafi',
            $serviceName,
            fn (PendingRequest $http) => $http->post($config['url'], $payload),
            $config['url'],
            $payload,
            $context,
            fn (array $answer) => $this->describe($answer),
        )->json() ?? [];
    }

    /**
     * WaafiPay's reference and outcome: the transaction state on success, else the error code and message.
     */
    private function describe(array $answer): array
    {
        return [
            'provider_reference' => $answer['params']['transactionId'] ?? null,
            'provider_status' => $this->isSuccess($answer)
                ? ($answer['params']['state'] ?? $answer['responseMsg'] ?? null)
                : trim(($answer['errorCode'] ?? '').' '.($answer['responseMsg'] ?? '')),
        ];
    }
}
