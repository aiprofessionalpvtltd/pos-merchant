<?php

namespace App\Services;

use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only plan and PIN summaries for the admin pages. Plans are loaded once,
 * so listing many accounts does not repeat that query.
 */
class AccountStatusService
{
    /** @var Collection<int, SubscriptionPlan>|null */
    private ?Collection $plans = null;

    private ?SubscriptionPlan $defaultPlan = null;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @return array{name: string, status: string, expires_at: ?Carbon, features: array<int, string>}
     */
    public function plan(?MerchantSubscription $subscription): array
    {
        $this->plans ??= $this->subscriptions->plans();
        $this->defaultPlan ??= $this->subscriptions->defaultPlan();

        $state = $this->subscriptions->buildState($subscription, $this->plans, $this->defaultPlan);

        return [
            'name' => SubscriptionService::planName($state->plan),
            'status' => $state->status,
            'expires_at' => $state->expiresAt,
            'features' => $state->features,
        ];
    }

    /**
     * Only whether a PIN exists: the API stores a hash, and no PIN is ever shown.
     *
     * @return array{state: 'set'|'not_set'|'locked', locked_until: ?Carbon, failed_attempts: int}
     */
    public function pin(User $user): array
    {
        $isLocked = $user->locked_until?->isFuture() ?? false;

        return [
            'state' => $isLocked ? 'locked' : ($user->hasPin() ? 'set' : 'not_set'),
            'locked_until' => $isLocked ? $user->locked_until : null,
            'failed_attempts' => (int) $user->pin_failed_attempts,
        ];
    }
}
