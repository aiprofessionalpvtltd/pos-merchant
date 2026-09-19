<?php

namespace App\Services\Subscriptions;

use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;

/**
 * A merchant's subscription as of now: which plan it is, whether it is usable,
 * and which features apply. Built by SubscriptionService.
 */
final class SubscriptionState
{
    /**
     * @param  'active'|'grace'|'expired'|'cancelled'  $status
     * @param  array<int, string>  $features  Features of the plan that currently applies
     * @param  array{ends_at: Carbon, days_remaining: int}|null  $grace
     */
    public function __construct(
        public readonly SubscriptionPlan $plan,
        public readonly SubscriptionPlan $effectivePlan,
        public readonly ?SubscriptionPlan $nextPlan,
        public readonly ?SubscriptionPlan $defaultPlan,
        public readonly ?MerchantSubscription $row,
        public readonly string $status,
        public readonly array $features,
        public readonly ?Carbon $startedAt,
        public readonly ?Carbon $expiresAt,
        public readonly ?array $grace,
        public readonly bool $canUpgrade,
        public readonly bool $canDowngrade,
        public readonly bool $canRenew,
        public readonly string $message,
    ) {}

    public function isDefaultPlan(): bool
    {
        return $this->plan->is_default;
    }
}
