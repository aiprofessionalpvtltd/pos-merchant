<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\ListProductsRequest;
use App\Http\Requests\API\V1\LookupProductRequest;
use App\Http\Requests\API\V1\StoreProductRequest;
use App\Http\Requests\API\V1\UpdateProductRequest;
use App\Models\Merchant;
use App\Services\CatalogueService;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        $result = $this->catalogue->paginate($this->merchant($request), $request->validated());

        return ApiResponse::success($result['items'], null, 200, ['pagination' => $result['pagination'], 'sync_cursor' => $result['sync_cursor']]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->catalogue->show($this->merchant($request), $id));
    }

    public function lookup(LookupProductRequest $request): JsonResponse
    {
        return ApiResponse::success($this->catalogue->lookup($this->merchant($request), $request->validated('barcode'), $request->validated('type') ?? 'shop'));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $result = $this->catalogue->create($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $merchant = $this->merchant($request);
        $body = $request->validated();
        $ifMatch = trim((string) $request->header('If-Match'), ' "') ?: null;

        $result = Idempotency::run("m{$merchant->id}:product:{$id}", $body['idempotency_key'], $body + ['if_match' => $ifMatch], fn () => $this->catalogue->update($merchant, $id, $body, $ifMatch));

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->catalogue->delete($this->merchant($request), $id), 'Product deleted');
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
