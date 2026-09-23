<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\CatalogueReportRequest;
use App\Http\Requests\API\V1\DashboardRequest;
use App\Http\Requests\API\V1\InventoryReportRequest;
use App\Http\Requests\API\V1\ProductsReportRequest;
use App\Http\Requests\API\V1\SalesReportRequest;
use App\Models\Merchant;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ReportService $reports,
    ) {}

    public function index(DashboardRequest $request): JsonResponse
    {
        $data = $this->dashboard->index($this->merchant($request), (int) ($request->validated('weeks') ?? 4), (int) ($request->validated('history_limit') ?? 10));

        return ApiResponse::success($data);
    }

    public function sales(SalesReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->reports->sales($this->merchant($request), $request->validated()));
    }

    public function inventory(InventoryReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->reports->inventory($this->merchant($request), $request->validated()));
    }

    public function products(ProductsReportRequest $request): JsonResponse
    {
        $result = $this->reports->products($this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
    }

    public function catalogue(CatalogueReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->reports->catalogue($this->merchant($request), $request->validated()));
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
