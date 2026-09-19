<?php

namespace App\Http\Resources\API\V1;

use App\Models\Merchant;
use App\Models\POSPermission;
use App\Models\Shift;
use App\Models\User;
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

        return $data;
    }

    private function userBlock(User $user, ?Merchant $merchant): array
    {
        $profile = $user->isEmployee() ? $user->employee : $merchant;

        $firstName = $profile?->first_name;
        $lastName = $profile?->last_name;

        return [
            'id' => $user->id,
            'type' => $user->user_type,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'short_name' => $this->initials(trim($firstName.' '.$lastName) ?: $user->name),
            'email' => $user->email,
            'phone_number' => $profile?->phone_number,
        ];
    }

    private function merchantBlock(Merchant $merchant): array
    {
        $rate = config('exelo.conversion_rate');

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
        $merchant->loadMissing('currentSubscription.subscriptionPlan');
        $subscription = $merchant->currentSubscription;

        if (! $subscription) {
            $planId = config('exelo.default_subscription_plan_id');

            return $this->planBlock($planId, null, 'active', null);
        }

        if ($subscription->is_canceled) {
            $status = 'canceled';
        } elseif ($subscription->end_date && now()->gt($subscription->end_date)) {
            $status = 'expired';
        } else {
            $status = 'active';
        }

        return $this->planBlock(
            $subscription->subscription_plan_id,
            $subscription->subscriptionPlan?->name,
            $status,
            $subscription->end_date,
        );
    }

    private function planBlock(int $planId, ?string $planName, string $status, mixed $endDate): array
    {
        $names = [1 => 'gold', 2 => 'silver'];

        $block = [
            'plan_id' => $planId,
            'plan' => $planName ? Str::lower(Str::before($planName, ' ')) : ($names[$planId] ?? null),
            'status' => $status,
        ];

        if (! $this->isFull) {
            $block['expires_at'] = $endDate ? ApiResponse::iso(\Illuminate\Support\Carbon::parse($endDate)->endOfDay()) : null;
        }

        return $block;
    }

    private function permissionsBlock(User $user): array
    {
        if ($user->isEmployee()) {
            $permissions = $user->employee->permissions()->with('permission')->get()->pluck('permission')->filter();
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
        $shift = Shift::where('user_id', $user->id)
            ->whereNotNull('start_time')
            ->whereNull('end_time')
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
