<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\AdjustStockRequest;
use App\Http\Requests\API\V1\ListAlertsRequest;
use App\Http\Requests\API\V1\TransferStockRequest;
use App\Models\Merchant;
use App\Services\StockService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function transfer(TransferStockRequest $request): JsonResponse
    {
        $result = $this->stock->transfer($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function adjust(AdjustStockRequest $request, int $productId): JsonResponse
    {
        $result = $this->stock->adjust($request->user(), $this->merchant($request), $productId, $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function alerts(ListAlertsRequest $request): JsonResponse
    {
        $result = $this->stock->alerts($this->merchant($request), $request->validated());

        return ApiResponse::success($result['items'], null, 200, ['pagination' => $result['pagination'], 'summary' => $result['summary']]);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
