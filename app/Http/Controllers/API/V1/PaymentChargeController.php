<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePaymentService;
use App\Support\ApiResponse;
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
        $merchant = $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);

        $invoice = Invoice::where('public_id', $chargeId)
            ->where('merchant_id', $merchant->id)
            ->first();

        if (! $invoice) {
            throw new ApiException('charge.not_found', 'We could not find that payment', 404);
        }

        $invoice = $this->payments->refresh($invoice);

        return ApiResponse::success($this->payments->chargePayload($invoice->refresh()));
    }
}
