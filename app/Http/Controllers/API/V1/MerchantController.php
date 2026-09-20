<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\UpdateMerchantProfileRequest;
use App\Http\Requests\API\V1\UpdateMerchantSettingsRequest;
use App\Http\Requests\API\V1\UpdateMerchantWalletsRequest;
use App\Models\Merchant;
use App\Services\MerchantProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    public function __construct(private readonly MerchantProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profiles->profile($this->merchant($request)));
    }

    public function update(UpdateMerchantProfileRequest $request): JsonResponse
    {
        $result = $this->profiles->updateProfile(
            $this->merchant($request),
            $request->validated(),
            trim((string) $request->header('If-Match'), ' "') ?: null,
        );

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function wallets(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profiles->wallets($this->merchant($request)));
    }

    public function updateWallets(UpdateMerchantWalletsRequest $request): JsonResponse
    {
        $result = $this->profiles->updateWallets(
            $this->merchant($request),
            $request->validated('wallets'),
            $request->validated('default_rail'),
        );

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profiles->settings($this->merchant($request)));
    }

    public function updateSettings(UpdateMerchantSettingsRequest $request): JsonResponse
    {
        $result = $this->profiles->updateSettings($this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message']);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
