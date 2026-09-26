<?php

namespace App\Http\Resources\API\V1;

use App\Models\Employee;
use App\Models\Shop;
use App\Services\MerchantProfileService;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything about one shop, as listed on the merchant account (GET /account).
 *
 * @property Shop $resource
 */
class ShopDetailResource extends JsonResource
{
    public function __construct(
        Shop $shop,
        private readonly bool $isActive = false,
        private readonly bool $withSubscriptionDetails = false,
    ) {
        parent::__construct($shop);
    }

    public function toArray(Request $request): array
    {
        $shop = $this->resource;
        $state = app(SubscriptionService::class)->state($shop);
        $wallets = app(MerchantProfileService::class)->wallets($shop);

        return [
            'id' => $shop->id,
            'business_name' => $shop->business_name,
            'phone_number' => $shop->phone_number,
            'email' => $shop->email,
            'address' => [
                'state' => $shop->state,
                'state_code' => $shop->state_code,
                'city' => $shop->city,
                'location' => $shop->location,
            ],
            'merchant_code' => $shop->merchant_code,
            'other_merchant_code' => $shop->other_merchant_code,
            // The full subscription (features, dates, upgrade options) is a superset of the short one.
            'subscription' => $this->withSubscriptionDetails
                ? (new SubscriptionResource($state))->resolve()
                : [
                    'plan_id' => $state->plan->id,
                    'plan' => $state->plan->key,
                    'status' => $state->status,
                    'expires_at' => ApiResponse::iso($state->expiresAt),
                ],
            'payout_wallets' => $wallets['wallets'],
            'default_rail' => $wallets['default_rail'],
            'staff_count' => (int) ($shop->staff_count ?? $shop->activeEmployees()->count()),
            'staff' => $shop->relationLoaded('activeEmployees')
                ? $shop->activeEmployees->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'role' => $employee->role,
                ])->values()->all()
                : [],
            'is_active' => $this->isActive,
            'created_at' => ApiResponse::iso($shop->created_at),
        ];
    }
}
