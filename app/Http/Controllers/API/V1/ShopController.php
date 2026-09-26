<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\StoreShopRequest;
use App\Http\Requests\API\V1\UpdateMerchantSettingsRequest;
use App\Http\Requests\API\V1\UpdateShopRequest;
use App\Services\ShopService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One owner, several shops. See docs/multiple-shop.md.
 */
class ShopController extends Controller
{
    public function __construct(private readonly ShopService $shops) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->shops->list($request->user()));
    }

    public function store(StoreShopRequest $request): JsonResponse
    {
        $result = $this->shops->open($request->user(), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->shops->profile($request->user(), $id));
    }

    public function update(UpdateShopRequest $request, int $id): JsonResponse
    {
        $result = $this->shops->update(
            $request->user(),
            $id,
            $request->validated(),
            trim((string) $request->header('If-Match'), ' "') ?: null,
        );

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function settings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->shops->settings($request->user(), $id));
    }

    public function updateSettings(UpdateMerchantSettingsRequest $request, int $id): JsonResponse
    {
        $result = $this->shops->updateSettings($request->user(), $id, $request->validated());

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $result = $this->shops->close($request->user(), $id, $request->header('X-EXELO-Confirmation'));

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function select(Request $request, int $id): JsonResponse
    {
        $result = $this->shops->select($request->user(), $id);

        return ApiResponse::success($result['data'], $result['message']);
    }
}
