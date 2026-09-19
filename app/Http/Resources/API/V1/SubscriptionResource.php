<?php

namespace App\Http\Resources\API\V1;

use App\Services\Subscriptions\SubscriptionState;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SubscriptionState $resource
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = $this->resource;

        return [
            'plan_id' => $state->plan->id,
            'plan' => $state->plan->key,
            'plan_name' => \App\Services\SubscriptionService::planName($state->plan),
            'status' => $state->status,
            'features' => $state->features,
            'started_at' => ApiResponse::iso($state->startedAt),
            'expires_at' => ApiResponse::iso($state->expiresAt),
            'renews_automatically' => false,
            'can_upgrade' => $state->canUpgrade,
            'can_downgrade' => $state->canDowngrade,
            'resubscribe_eligible' => $state->canRenew,
            'grace' => $state->grace ? [
                'ends_at' => ApiResponse::iso($state->grace['ends_at']),
                'days_remaining' => $state->grace['days_remaining'],
            ] : null,
        ];
    }
}
