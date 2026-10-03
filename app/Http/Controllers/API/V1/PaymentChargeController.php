<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\ConfirmPaymentRequest;
use App\Models\Invoice;
use App\Services\InvoicePaymentService;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Charge polling for every payment the shop owns: subscriptions and sales.
 */
class PaymentChargeController extends Controller
{
    public function __construct(private readonly InvoicePaymentService $payments) {}

    public function show(Request $request, string $chargeId): JsonResponse
    {
        $invoice = $this->payments->rememberExpiry($this->payments->refresh($this->ownedInvoice($request, $chargeId)));

        return ApiResponse::success(
            $this->payments->chargePayload($invoice),
            $invoice->status === 'Expired' ? $invoice->error_reason : null,
        );
    }

    /**
     * The app parsed the payer's eDahab SMS and is submitting Code + Txn Id.
     */
    public function confirm(ConfirmPaymentRequest $request, string $chargeId): JsonResponse
    {
        $invoice = $this->ownedInvoice($request, $chargeId);
        $body = $request->validated();

        $result = Idempotency::run("m{$invoice->merchant_id}:charge-confirm:{$invoice->id}", $body['idempotency_key'], $body, function () use ($invoice, $body) {
            $invoice = $this->payments->confirm($invoice, $body)->fresh();

            return [
                'data' => $this->payments->chargePayload($invoice),
                'message' => match ($invoice->status) {
                    'Paid' => 'Payment received',
                    'Expired' => $invoice->error_reason ?: InvoicePaymentService::EXPIRED_MESSAGE,
                    default => null,
                },
                'status' => 200,
            ];
        });

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function cancel(Request $request, string $chargeId): JsonResponse
    {
        $invoice = $this->payments->cancel($this->ownedInvoice($request, $chargeId));

        return ApiResponse::success(
            ['charge_id' => $invoice->public_id, 'status' => strtolower($invoice->status)],
            'Payment cancelled',
        );
    }

    private function ownedInvoice(Request $request, string $chargeId): Invoice
    {
        $merchant = $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);

        $invoice = Invoice::where('public_id', $chargeId)
            ->where('merchant_id', $merchant->id)
            ->first();

        if (! $invoice) {
            throw new ApiException('charge.not_found', 'We could not find that payment', 404);
        }

        return $invoice;
    }
}
