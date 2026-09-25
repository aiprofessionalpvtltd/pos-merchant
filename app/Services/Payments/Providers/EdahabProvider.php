<?php

namespace App\Services\Payments\Providers;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Services\Payments\Contracts\WalletProvider;
use App\Services\Payments\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * eDahab. Every request is signed: hash = sha256(exact JSON body + secret), sent as ?hash=. See docs/edahab.md.
 */
class EdahabProvider implements WalletProvider
{
    private const STATUS_USER_DECLINED = 7;

    public function __construct(private readonly ProviderHttp $http) {}

    /**
     * eDahab waits for the customer's answer before replying. A declined prompt
     * (StatusCode 7) leaves the invoice open on eDahab's side, so it stays pending here too.
     */
    public function issue(string $walletE164, int $amount, string $currency, string $description): array
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

        [$response, $hash] = $this->post('IssueInvoice', $payload, ['our_reference' => $transactionId]);
        $body = $response->json();

        if (($body['StatusDescription'] ?? null) === 'Validation Error') {
            throw new ApiException('payment.wallet_invalid', $body['ValidationErrors'][0]['ErrorMessage'] ?? 'That wallet number is not valid', 422, [], 'wallet_number');
        }

        if (empty($body['InvoiceId'])) {
            Log::error('eDahab IssueInvoice rejected', ['body' => $response->body()]);

            throw new ApiException('payment.provider_unavailable', 'The wallet provider is unavailable. Try again.', 502);
        }

        return [
            'invoice_id' => (string) $body['InvoiceId'],
            'transaction_id' => $transactionId,
            'hash' => $hash,
            'prompt' => (int) ($body['StatusCode'] ?? 0) === self::STATUS_USER_DECLINED ? 'declined' : null,
        ];
    }

    public function status(Invoice $invoice): array
    {
        $payload = ['apiKey' => config('exelo.providers.edahab.api_key'), 'invoiceId' => $invoice->invoice_id];

        [$response] = $this->post('checkInvoiceStatus', $payload, ['invoice_id' => $invoice->id, 'our_reference' => $invoice->transaction_id]);
        $body = $response->json();

        // A declined invoice stays open, so eDahab's "Unpaid" is still pending.
        $status = match (strtolower((string) ($body['InvoiceStatus'] ?? ''))) {
            'paid' => 'Paid',
            'pending', 'unpaid', '' => 'Pending',
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

    /**
     * @param  array{invoice_id?: ?int, our_reference?: ?string}  $context
     * @return array{0: Response, 1: string}
     */
    private function post(string $endpoint, array $payload, array $context = []): array
    {
        $config = config('exelo.providers.edahab');
        $body = json_encode($payload);
        $hash = hash('sha256', $body.$config['secret']);
        $url = $config['base_url'].'/'.$endpoint.'?hash='.$hash;

        $response = $this->http->send(
            'edahab',
            $endpoint,
            fn (PendingRequest $http) => $http->withBody($body, 'application/json')->post($url),
            $url,
            $payload,
            $context + ['timeout' => $config['timeout']],
            fn (array $answer) => $this->describe($answer),
        );

        return [$response, $hash];
    }

    /**
     * eDahab's reference and outcome, e.g. "Unpaid · 7 User declined" or "Approved".
     */
    private function describe(array $answer): array
    {
        $status = $answer['InvoiceStatus'] ?? $answer['TransactionStatus'] ?? null;
        $code = $answer['StatusCode'] ?? null;

        if ($code !== null && (int) $code !== 0) {
            $status = trim(($status ?? '').' · '.$code.' '.($answer['StatusDescription'] ?? ''), ' ·');
        } elseif ($status === null) {
            $status = $answer['StatusDescription'] ?? null;
        }

        return [
            'provider_reference' => $answer['TransactionId'] ?? $answer['InvoiceId'] ?? null,
            'provider_status' => $status,
        ];
    }
}
