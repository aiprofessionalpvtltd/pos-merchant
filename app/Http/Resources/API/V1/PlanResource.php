<?php

namespace App\Http\Resources\API\V1;

use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SubscriptionPlan $resource
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        $slsh = (int) $plan->price_slsh;

        $data = [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => SubscriptionService::planName($plan),
            'price' => $slsh === 0
                ? ['amount' => 0, 'currency' => config('exelo.alt_currency'), 'display' => 'Free']
                : Money::format($slsh, config('exelo.alt_currency')),
        ];

        if ($slsh > 0) {
            $data['price_alt'] = Money::usd((int) round($plan->price * 100));
        }

        return $data + [
            'billing_period' => $plan->duration,
            'features' => $plan->features ?? [],
            'is_default' => $plan->is_default,
        ];
    }
}
