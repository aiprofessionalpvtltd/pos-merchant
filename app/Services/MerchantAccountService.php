<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\API\V1\ShopDetailResource;
use App\Models\MerchantAccount;
use App\Models\Shop;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The merchant (table `merchants`) and the shops it owns (table `shops`). See docs/data-model.md.
 */
class MerchantAccountService
{
    /** Staff listed per shop in GET /account; the full list is GET /account/employees. */
    public const STAFF_PREVIEW = 5;

    /**
     * The merchant with every shop and each shop's details.
     */
    public function overview(User $user): array
    {
        $account = $this->account($user);

        return [
            'merchant' => $this->merchantSummary($user),
            'shops' => $this->shops($user),
            'shops_count' => $user->shops()->where('is_approved', true)->count(),
            'active_shop_id' => $user->actingMerchant()?->id,
            'onboarding' => [
                'phone_verified' => $account->isPhoneVerified(),
                'next_step' => $user->onboardingNextStep(),
            ],
        ];
    }

    /**
     * The merchant's own details (the `merchants` row).
     *
     * @return array<string, mixed>
     */
    public function merchantSummary(User $user): array
    {
        $account = $this->account($user);

        return [
            'id' => $account->id,
            'first_name' => $account->first_name,
            'last_name' => $account->last_name,
            'dob' => $account->dob,
            'email' => $account->email,
            'phone_number' => $account->phone_number,
            'phone_verified' => $account->isPhoneVerified(),
            'phone_verified_at' => ApiResponse::iso($account->phone_verified_at),
            'created_at' => ApiResponse::iso($account->created_at),
        ];
    }

    /**
     * Every open shop the merchant owns with its details. With `$withSubscriptionDetails` each shop's
     * `subscription` also carries the plan's features, dates and upgrade options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function shops(User $user, bool $withSubscriptionDetails = false): array
    {
        $this->account($user);

        $activeId = $user->actingMerchant()?->id;

        // Counts and a short staff preview per shop in two queries, not one per shop.
        return $user->shops()
            ->where('is_approved', true)
            ->withCount(['activeEmployees as staff_count'])
            ->with(['activeEmployees' => fn ($staff) => $staff->orderBy('first_name')->orderBy('id')->limit(self::STAFF_PREVIEW)])
            ->orderBy('id')
            ->get()
            ->map(fn (Shop $shop) => (new ShopDetailResource($shop, $shop->id === $activeId, $withSubscriptionDetails))->resolve())
            ->values()
            ->all();
    }

    /**
     * The merchant's own details. The phone number isn't changed here: it's the verified sign-in number.
     *
     * @param  array{first_name?: string, last_name?: string, dob?: string, email?: ?string}  $data
     */
    public function update(User $user, array $data): array
    {
        $account = $this->account($user);

        DB::transaction(function () use ($user, $account, $data) {
            $account->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'dob', 'email'])));

            if ($account->isDirty(['first_name', 'last_name'])) {
                $user->forceFill(['name' => $account->fullName()])->save();
            }

            $account->save();
        });

        Log::info('Merchant updated', ['merchant_id' => $account->id, 'fields' => array_keys($data)]);

        return $this->overview($user->fresh());
    }

    private function account(User $user): MerchantAccount
    {
        if (! $user->isMerchantAccount() || ! $user->merchantAccount) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }

        return $user->merchantAccount;
    }
}
