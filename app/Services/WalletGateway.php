<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\ApiLog;
use App\Models\Invoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the eDahab and Waafi (Zaad) APIs. Every method makes a single call;
 * callers poll rather than the gateway looping.
 */
class WalletGateway
{
    /**
     * @return array{invoice_id: string, transaction_id: string, hash: string}
     */
    public function issue(string $rail, string $walletE164, int $amount, string $currency): array
    {
        return $rail === 'edahab'
            ? $this->issueEdahab($walletE164, $amount, $currency)
            : $this->issueZaad($walletE164, $amount, $currency);
    }

    /**
     * @return array{status: string, provider_transaction_id: ?string, reason: ?string}
     */
    public function status(Invoice $invoice): array
    {
        return $invoice->rail === 'edahab' ? $this->edahabStatus($invoice) : $this->zaadStatus($invoice);
    }

    private function issueEdahab(string $walletE164, int $amount, string $currency): array
    {
        $config = config('exelo.providers.edahab');
        $transactionId = 'txn_1_'.round(microtime(true) * 1000);

        $payload = [
            'apiKey' => $config['api_key'],
            'EdahabNumber' => substr($walletE164, 4),
            'Amount' => $amount,
            'AgentCode' => $config['agent_code'],
            'transactionId' => $transactionId,
            'Currency' => $currency,
        ];

        [$response, $hash, $url] = $this->postEdahab('IssueInvoice', $payload);
        $body = $response->json();

        if (($body['StatusDescription'] ?? null) === 'Validation Error') {
            throw new ApiException('payment.wallet_invalid', $body['ValidationErrors'][0]['ErrorMessage'] ?? 'That wallet number is not valid', 422, [], 'wallet_number');
        }

        if (empty($body['InvoiceId'])) {
            Log::error('eDahab IssueInvoice rejected', ['body' => $response->body()]);

            throw new ApiException('payment.provider_unavailable', 'The wallet provider is unavailable. Try again.', 502);
        }

        return ['invoice_id' => (string) $body['InvoiceId'], 'transaction_id' => $transactionId, 'hash' => $hash];
    }

    private function edahabStatus(Invoice $invoice): array
    {
        $payload = ['apiKey' => config('exelo.providers.edahab.api_key'), 'invoiceId' => $invoice->invoice_id];

        [$response] = $this->postEdahab('checkInvoiceStatus', $payload);
        $body = $response->json();

        $status = match (strtolower((string) ($body['InvoiceStatus'] ?? ''))) {
            'paid' => 'Paid',
            'pending', '' => 'Pending',
            'expired' => 'Expired',
            'cancelled', 'canceled' => 'Cancelled',
            default => 'Failed',
        };

        return [
            'status' => $status,
            'provider_transaction_id' => $body['TransactionId'] ?? null,
            'reason' => $status === 'Failed' ? ($body['InvoiceStatus'] ?? null) : null,
        ];
    }

    private function issueZaad(string $walletE164, int $amount, string $currency): array
    {
        $referenceId = (string) random_int(100000, 999999);

        $body = $this->postWaafi('API_PREAUTHORIZE', [
            'paymentMethod' => 'MWALLET_ACCOUNT',
            'payerInfo' => ['accountNo' => substr($walletE164, 1)],
            'transactionInfo' => [
                'referenceId' => $referenceId,
                'invoiceId' => (string) random_int(100000, 999999),
                'amount' => $amount,
                'currency' => $currency,
                'description' => 'EXELO signup',
                'paymentBrand' => 'WAAFI',
            ],
        ]);

        if (($body['errorCode'] ?? null) != 0) {
            throw new ApiException('payment.wallet_invalid', $body['responseMsg'] ?? 'That wallet number is not valid', 422, [], 'wallet_number');
        }

        return [
            'invoice_id' => (string) $body['params']['referenceId'],
            'transaction_id' => (string) $body['params']['transactionId'],
            'hash' => '0',
        ];
    }

    private function zaadStatus(Invoice $invoice): array
    {
        $body = $this->postWaafi('API_PREAUTHORIZE_COMMIT', [
            'transactionId' => $invoice->transaction_id,
            'referenceId' => $invoice->invoice_id,
            'description' => 'Commit transaction',
        ]);

        $isApproved = ($body['errorCode'] ?? null) == 0 && ($body['params']['state'] ?? null) === 'approved';

        return [
            'status' => $isApproved ? 'Paid' : 'Pending',
            'provider_transaction_id' => $isApproved ? ($body['params']['transactionId'] ?? null) : null,
            'reason' => null,
        ];
    }

    /**
     * @return array{0: Response, 1: string, 2: string}
     */
    private function postEdahab(string $endpoint, array $payload): array
    {
        $config = config('exelo.providers.edahab');
        $body = json_encode($payload);
        $hash = hash('sha256', $body.$config['secret']);
        $url = $config['base_url'].'/'.$endpoint.'?hash='.$hash;

        $response = $this->send(fn (PendingRequest $http) => $http->withBody($body, 'application/json')->post($url), $url, $payload);

        return [$response, $hash, $url];
    }

    private function postWaafi(string $serviceName, array $serviceParams): array
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

        return $this->send(fn (PendingRequest $http) => $http->post($config['url'], $payload), $config['url'], $payload)->json() ?? [];
    }

    private function send(callable $call, string $url, array $payload): Response
    {
        try {
            $response = $call(Http::timeout(config('exelo.providers.timeout'))->acceptJson());
        } catch (\Throwable $e) {
            Log::error('Wallet provider unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            throw new ApiException('payment.provider_unavailable', 'The wallet provider is unavailable. Try again.', 502);
        }

        ApiLog::create([
            'url' => $url,
            'payload' => $this->redact($payload),
            'status_code' => $response->status(),
            'response_body' => $response->json(),
        ]);

        if (! $response->successful()) {
            throw new ApiException('payment.provider_unavailable', 'The wallet provider is unavailable. Try again.', 502);
        }

        return $response;
    }

    private function redact(array $payload): array
    {
        unset($payload['apiKey']);

        if (isset($payload['serviceParams'])) {
            unset($payload['serviceParams']['apiKey']);
        }

        return $payload;
    }
}
