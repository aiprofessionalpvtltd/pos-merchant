<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns users into the rows the admin users page shows. Expects the users to be
 * loaded with `merchant` and `employee.merchant` (each with `currentSubscription.subscriptionPlan`)
 * so the list does not query per row.
 */
class UserDirectoryService
{
    public function __construct(private readonly AccountStatusService $status) {}

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $users): Collection
    {
        return $users->map(function (User $user) {
            $merchant = $user->actingMerchant();
            $isAppUser = in_array($user->user_type, ['merchant', 'employee'], true);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => $user->user_type,
                'phone_number' => $user->isEmployee() ? $user->employee->phone_number : $merchant?->phone_number,
                'business_name' => $merchant?->business_name,
                'plan' => $merchant ? $this->status->plan($merchant->currentSubscription) : null,
                'pin' => $isAppUser ? $this->status->pin($user) : null,
                'registered_at' => $user->created_at,
            ];
        });
    }
}
