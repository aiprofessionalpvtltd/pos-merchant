<?php

namespace App\Http\Resources\API\V1;

use App\Models\Merchant;
use App\Models\POSPermission;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShopService;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Everything the app needs to boot: profile, merchant, plan and permissions.
 *
 * @property User $resource
 */
class SessionResource extends JsonResource
{
    public function __construct(User $user, private readonly bool $isFull = false)
    {
        parent::__construct($user);
    }

    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $merchant = $user->actingMerchant();

        $data = [
            'user' => $this->userBlock($user, $merchant),
            'merchant' => $merchant ? $this->merchantBlock($merchant) : null,
            'subscription' => $merchant ? $this->subscriptionBlock($merchant) : null,
            'permissions' => $this->permissionsBlock($user),
        ];

        if ($this->isFull) {
            $data['shift'] = $this->shiftBlock($user);
            $data['server_time'] = ApiResponse::iso(now());
        }

        // Every shop this person can switch to (docs/multiple-shop.md).
        $data['shops'] = app(ShopService::class)->summaries($user);

        // First-time onboarding for a merchant account: verify its phone, then create its first shop.
        $data['onboarding'] = $user->isEmployee() ? null : [
            'phone_verified' => (bool) $user->merchantAccount?->isPhoneVerified(),
            'next_step' => $user->onboardingNextStep(),
        ];

        return $data;
    }

    private function userBlock(User $user, ?Merchant $merchant): array
    {
        // Staff are described by their staff record; a merchant by their merchants row
        // (falling back to the shop for sign-ins that have none).
        $profile = $user->isEmployee()
            ? ($user->actingEmployee() ?? $user->employee)
            : ($user->merchantAccount ?? $merchant);

        $firstName = $profile?->first_name;
        $lastName = $profile?->last_name;
        $phone = $profile?->phone_number;

        return [
            'id' => $user->id,
            'type' => $user->user_type,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'short_name' => $this->initials(trim($firstName.' '.$lastName) ?: $user->name),
            'email' => $user->email,
            'phone_number' => $phone,
        ];
    }

    private function merchantBlock(Merchant $merchant): array
    {
        $rate = $merchant->effectiveExchangeRate();

        $block = [
            'id' => $merchant->id,
            'business_name' => $merchant->business_name,
            'merchant_code' => $merchant->merchant_code,
        ];

        if ($this->isFull) {
            $block['other_merchant_code'] = $merchant->other_merchant_code;
        }

        $block += [
            'state' => $merchant->state,
            'city' => $merchant->city ?? $merchant->location,
            'location' => $merchant->location,
            'currency' => 'USD',
            'alt_currency' => config('exelo.alt_currency'),
            'exchange_rate' => floor($rate) == $rate ? (int) $rate : $rate,
        ];

        if ($this->isFull) {
            $block['wallets'] = [
                'zaad_number' => $merchant->zaad_number,
                'edahab_number' => $merchant->edahab_number,
            ];
        }

        return $block;
    }

    private function subscriptionBlock(Merchant $merchant): array
    {
        $state = app(SubscriptionService::class)->state($merchant);

        $block = [
            'plan_id' => $state->plan->id,
            'plan' => $state->plan->key,
            'status' => $state->status,
        ];

        if (! $this->isFull) {
            $block['expires_at'] = ApiResponse::iso($state->expiresAt);
        }

        return $block;
    }

    private function permissionsBlock(User $user): array
    {
        if ($user->isEmployee()) {
            // Permissions of their staff record in the current shop.
            $permissions = $user->actingEmployee()?->permissions()->with('permission')->get()->pluck('permission')->filter() ?? collect();
        } else {
            $permissions = POSPermission::all();
        }

        return $permissions
            ->map(fn (POSPermission $permission) => ['key' => $permission->permission_key, 'name' => $permission->name])
            ->values()
            ->all();
    }

    private function shiftBlock(User $user): array
    {
        // The open shift in the current shop only.
        $shopId = $user->actingMerchant()?->id;

        $shift = $shopId === null ? null : Shift::open()
            ->where('user_id', $user->id)
            ->forShop($shopId)
            ->latest('id')
            ->first();

        return [
            'active' => $shift !== null,
            'shift_id' => $shift?->id,
            'started_at' => $shift ? ApiResponse::iso($shift->start_time) : null,
        ];
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
