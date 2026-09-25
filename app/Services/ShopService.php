<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\API\V1\SessionResource;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One owner, several shops: list, switch, open, edit and close (docs/multiple-shop.md).
 * The active shop lives on the token; User::actingMerchant() reads it.
 */
class ShopService
{
    public const CONFIRMATION_SCOPE_CLOSE = 'shops.delete';

    public function __construct(
        private readonly RegistrationService $registration,
        private readonly MerchantProfileService $profiles,
        private readonly EmployeeService $employees,
        private readonly AuthService $auth,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function summaries(User $user): array
    {
        $activeId = $user->actingMerchant()?->id;
        $role = $user->isEmployee() ? 'staff' : 'owner';

        return $user->accessibleShops()
            ->map(fn (Merchant $shop) => $this->summary($shop, $role, $shop->id === $activeId))
            ->values()
            ->all();
    }

    /**
     * @return array{shops: array<int, array<string, mixed>>, active_shop_id: ?int}
     */
    public function list(User $user): array
    {
        return ['shops' => $this->summaries($user), 'active_shop_id' => $user->actingMerchant()?->id];
    }

    public function find(User $user, int $shopId): Merchant
    {
        return $user->accessibleShop($shopId)
            ?? throw new ApiException('shop.not_found', 'We could not find that shop', 404);
    }

    /**
     * Makes the shop the active one for the token this request came with.
     */
    public function select(User $user, int $shopId): array
    {
        $shop = $this->find($user, $shopId);
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new ApiException('auth.token_invalid', 'Sign in again to switch shop', 401);
        }

        $token->forceFill(['merchant_id' => $shop->id])->save();

        return ['data' => (new SessionResource($user, true))->resolve(), 'message' => 'Now working in '.$shop->business_name];
    }

    /**
     * @param  array<string, mixed>  $input  validated StoreShopRequest fields
     */
    public function open(User $owner, array $input): array
    {
        $this->assertOwner($owner);

        $current = $owner->actingMerchant() ?? $owner->accessibleShops()->first();

        ['merchant' => $shop, 'plan' => $plan] = $this->registration->openShop($owner, $input, [
            'first_name' => $current?->first_name,
            'last_name' => $current?->last_name,
            'dob' => $current?->dob,
        ]);

        $owner->forgetAccessibleShops();

        Log::info('Shop opened', ['merchant_id' => $shop->id, 'owner_id' => $owner->id]);

        return [
            'data' => [
                'shop' => $this->summary($shop, 'owner', false),
                'subscription' => ['plan' => $plan->key, 'status' => 'active'],
            ],
            'message' => "{$shop->business_name} is open. Switch to it to start selling.",
        ];
    }

    public function profile(User $user, int $shopId): array
    {
        return $this->profiles->profile($this->find($user, $shopId));
    }

    /**
     * @param  array<string, mixed>  $data  validated profile fields
     */
    public function update(User $owner, int $shopId, array $data, ?string $ifMatch): array
    {
        $this->assertOwner($owner);

        return $this->profiles->updateProfile($this->find($owner, $shopId), $data, $ifMatch);
    }

    /**
     * Closes a shop: removes its staff, moves tokens acting for it to another of the
     * owner's shops, then soft-deletes it. Orders, stock and payments are kept.
     */
    public function close(User $owner, int $shopId, ?string $confirmationToken): array
    {
        $this->assertOwner($owner);
        $this->auth->assertConfirmation($owner, $confirmationToken, self::CONFIRMATION_SCOPE_CLOSE);

        $shop = $this->find($owner, $shopId);
        $fallback = $owner->accessibleShops()->first(fn (Merchant $other) => $other->id !== $shop->id)
            ?? throw new ApiException('shop.last_shop', 'Your only shop can\'t be closed', 409);

        $hasPendingPayment = Invoice::where('merchant_id', $shop->id)
            ->where('status', 'Pending')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($hasPendingPayment) {
            throw new ApiException('shop.payments_pending', 'A payment for this shop is still waiting. Try again when it settles or expires.', 409);
        }

        $staffRemoved = DB::transaction(function () use ($owner, $shop, $fallback) {
            $staff = Employee::active()->where('merchant_id', $shop->id)->get();

            foreach ($staff as $employee) {
                $this->employees->remove($owner, $shop, $employee->id);
            }

            PersonalAccessToken::where('merchant_id', $shop->id)->update(['merchant_id' => $fallback->id]);

            $shop->delete();

            return $staff->count();
        });

        $this->auth->consumeConfirmation($owner, $confirmationToken, self::CONFIRMATION_SCOPE_CLOSE);
        $owner->forgetAccessibleShops();

        $token = $owner->currentAccessToken();
        $activeId = $token instanceof PersonalAccessToken ? $token->fresh()?->merchant_id : null;

        Log::info('Shop closed', ['merchant_id' => $shop->id, 'owner_id' => $owner->id, 'staff_removed' => $staffRemoved]);

        return [
            'data' => ['shop_id' => $shop->id, 'status' => 'closed', 'staff_removed' => $staffRemoved, 'active_shop_id' => $activeId ?? $fallback->id],
            'message' => "{$shop->business_name} is closed",
        ];
    }

    private function assertOwner(User $user): void
    {
        if ($user->isEmployee()) {
            throw new ApiException('auth.merchant_only', 'Only the shop owner can do this', 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Merchant $shop, string $role, bool $isActive): array
    {
        return [
            'id' => $shop->id,
            'business_name' => $shop->business_name,
            'phone_number' => $shop->phone_number,
            'location' => $shop->location,
            'role' => $role,
            'plan' => $this->subscriptions->state($shop)->plan->key,
            'is_active' => $isActive,
        ];
    }
}
