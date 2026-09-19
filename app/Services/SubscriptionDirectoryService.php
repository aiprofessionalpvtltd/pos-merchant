<?php

namespace App\Services;

use App\Models\MerchantSubscription;

/**
 * What the admin subscriptions list shows for one row. Expects the row loaded with
 * `merchant`, `subscriptionPlan`, `nextPlan` and `invoice`.
 */
class SubscriptionDirectoryService
{
    /** @var array<int, int>|null merchant's newest subscription id => position */
    private ?array $currentIds = null;

    public function __construct(private readonly AccountStatusService $status) {}

    /**
     * @return array{is_current: bool, state: array{key: string, label: string}, next_plan: ?string, paid_by: ?string, merchant_name: string, phone_number: ?string}
     */
    public function listRow(MerchantSubscription $subscription): array
    {
        $isCurrent = ! $subscription->trashed() && $this->isCurrent($subscription);

        return [
            'is_current' => $isCurrent,
            'state' => $this->state($subscription, $isCurrent),
            'next_plan' => $subscription->nextPlan ? SubscriptionService::planName($subscription->nextPlan) : null,
            'paid_by' => $subscription->invoice?->rail ? ucfirst($subscription->invoice->rail) : null,
            'merchant_name' => $subscription->merchant->business_name
                ?? trim($subscription->merchant->first_name.' '.$subscription->merchant->last_name)
                ?: 'N/A',
            'phone_number' => $subscription->merchant->phone_number,
        ];
    }

    /**
     * @return array{key: string, label: string}
     */
    private function state(MerchantSubscription $subscription, bool $isCurrent): array
    {
        if ($subscription->trashed()) {
            return ['key' => 'replaced', 'label' => 'Replaced'];
        }

        // Older periods are history: only the newest row decides what the shop can use.
        if (! $isCurrent) {
            return ['key' => 'past', 'label' => 'Past'];
        }

        $status = $this->status->plan($subscription)['status'];

        return ['key' => $status, 'label' => ucfirst($status)];
    }

    private function isCurrent(MerchantSubscription $subscription): bool
    {
        $this->currentIds ??= MerchantSubscription::query()
            ->selectRaw('max(id) as id')
            ->groupBy('merchant_id')
            ->pluck('id')
            ->flip()
            ->all();

        return isset($this->currentIds[$subscription->id]);
    }
}
