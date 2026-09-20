<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\AddCartItemRequest;
use App\Http\Requests\API\V1\CartTypeRequest;
use App\Http\Requests\API\V1\HoldCartRequest;
use App\Http\Requests\API\V1\PayCartRequest;
use App\Http\Requests\API\V1\SyncCartRequest;
use App\Http\Requests\API\V1\UpdateCartItemRequest;
use App\Models\Merchant;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts, private readonly CheckoutService $checkout) {}

    public function show(CartTypeRequest $request): JsonResponse
    {
        return ApiResponse::success($this->carts->show($request->user(), $this->merchant($request), $request->validated('type') ?? 'shop'));
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        return ApiResponse::success($this->carts->addItem($request->user(), $this->merchant($request), $request->validated()));
    }

    public function updateItem(UpdateCartItemRequest $request, int $productId): JsonResponse
    {
        $ifMatch = trim((string) $request->header('If-Match'), ' "') ?: null;

        return ApiResponse::success($this->carts->updateItem(
            $request->user(), $this->merchant($request), $productId, $request->validated('type') ?? 'shop', $request->validated(), $ifMatch,
        ));
    }

    public function removeItem(CartTypeRequest $request, int $productId): JsonResponse
    {
        return ApiResponse::success($this->carts->removeItem($request->user(), $this->merchant($request), $productId, $request->validated('type') ?? 'shop'));
    }

    public function clear(CartTypeRequest $request): JsonResponse
    {
        return ApiResponse::success($this->carts->clear($request->user(), $this->merchant($request), $request->validated('type') ?? 'shop'), 'Sale cancelled');
    }

    public function sync(SyncCartRequest $request): JsonResponse
    {
        $result = $this->carts->sync($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success(['results' => $result['results'], 'cart' => $result['cart']], $result['message']);
    }

    public function pay(PayCartRequest $request): JsonResponse
    {
        $result = $this->checkout->pay($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function hold(HoldCartRequest $request): JsonResponse
    {
        $result = $this->checkout->hold($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
