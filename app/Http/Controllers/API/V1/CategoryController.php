<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\ListCategoriesRequest;
use App\Http\Requests\API\V1\StoreCategoryRequest;
use App\Models\Merchant;
use App\Services\CatalogueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(ListCategoriesRequest $request): JsonResponse
    {
        return ApiResponse::success($this->catalogue->categories($this->merchant($request), $request->validated()));
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $result = $this->catalogue->createCategory($this->merchant($request), $request->validated('name'), $request->validated('idempotency_key'));

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
