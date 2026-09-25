<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\ApiLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The HTTP call every wallet provider goes through: timeout, error mapping and
 * one api_logs row per call (credentials removed), including calls that never
 * got an answer.
 */
class ProviderHttp
{
    /**
     * @param  callable(\Illuminate\Http\Client\PendingRequest): Response  $call
     * @param  array{invoice_id?: ?int, our_reference?: ?string, timeout?: int}  $context
     * @param  (callable(array): array{provider_reference?: ?string, provider_status?: ?string})|null  $describe  Pulls the provider's reference and outcome out of a decoded response
     */
    public function send(string $provider, string $operation, callable $call, string $url, array $payload, array $context = [], ?callable $describe = null): Response
    {
        $log = [
            'provider' => $provider,
            'operation' => $operation,
            'invoice_id' => $context['invoice_id'] ?? null,
            'our_reference' => $context['our_reference'] ?? null,
            'url' => $url,
            'payload' => ApiLog::redact($payload),
        ];

        $startedAt = hrtime(true);

        try {
            $response = $call(Http::timeout($context['timeout'] ?? config('exelo.providers.timeout'))->acceptJson());
        } catch (\Throwable $e) {
            Log::error('Wallet provider unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            ApiLog::create($log + ['duration_ms' => $this->elapsed($startedAt), 'error' => $e->getMessage()]);

            throw new ApiException('payment.provider_unavailable', 'The wallet provider is unavailable. Try again.', 502);
        }

        $body = $response->json();
        $described = is_array($body) && $describe ? $describe($body) : [];

        ApiLog::create($log + [
            'status_code' => $response->status(),
            'response_body' => $body,
            'provider_reference' => $this->short($described['provider_reference'] ?? null, 100),
            'provider_status' => $this->short($described['provider_status'] ?? null, 255),
            'duration_ms' => $this->elapsed($startedAt),
            'error' => $response->successful() ? null : 'HTTP '.$response->status(),
        ]);

        if (! $response->successful()) {
            throw new ApiException('payment.provider_unavailable', 'The wallet provider is unavailable. Try again.', 502);
        }

        return $response;
    }

    private function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function short(mixed $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit((string) $value, $length, '');
    }
}
