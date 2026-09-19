<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\CancelSubscriptionRequest;
use App\Http\Requests\API\V1\ChangeSubscriptionRequest;
use App\Http\Resources\API\V1\PlanResource;
use App\Http\Resources\API\V1\SubscriptionResource;
use App\Models\Merchant;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function plans(): JsonResponse
    {
        return ApiResponse::success(['plans' => PlanResource::collection($this->subscriptions->plans())->resolve()]);
    }

    public function show(Request $request): JsonResponse
    {
        $state = $this->subscriptions->state($this->merchant($request));

        return ApiResponse::success((new SubscriptionResource($state))->resolve(), $state->message);
    }

    public function change(ChangeSubscriptionRequest $request): JsonResponse
    {
        $result = $this->subscriptions->change(
            $this->ownerMerchant($request),
            (int) $request->validated('plan_id'),
            $request->validated('rail'),
            $request->validated('wallet_number'),
            $request->validated('idempotency_key'),
        );

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function cancel(CancelSubscriptionRequest $request): JsonResponse
    {
        $result = $this->subscriptions->cancel(
            $this->ownerMerchant($request),
            $request->validated('reason'),
            $request->validated('comment'),
        );

        return ApiResponse::success($result['data'], $result['message']);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }

    /**
     * Only the shop owner can change what the shop pays for.
     */
    private function ownerMerchant(Request $request): Merchant
    {
        if ($request->user()->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return $this->merchant($request);
    }
}
