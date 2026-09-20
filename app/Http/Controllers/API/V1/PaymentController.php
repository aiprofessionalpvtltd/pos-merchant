<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\CreateChargeRequest;
use App\Http\Requests\API\V1\QuotePaymentRequest;
use App\Models\Merchant;
use App\Services\ChargeService;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments, private readonly ChargeService $charges) {}

    public function methods(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payments->methods($this->merchant($request)));
    }

    public function quote(QuotePaymentRequest $request): JsonResponse
    {
        return ApiResponse::success($this->payments->quote($this->merchant($request), $request->validated()));
    }

    public function createCharge(CreateChargeRequest $request): JsonResponse
    {
        $result = $this->charges->create($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
