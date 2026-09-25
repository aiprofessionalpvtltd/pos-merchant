<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin changes to the plan catalogue. Rules the app depends on:
 * exactly one default plan (the one every merchant falls back to), a key that never
 * changes once set (the app gates on it), and no deleting a plan merchants are on.
 */
class SubscriptionPlanService
{
    /**
     * @param  array{name: string, key: string, price: numeric, price_slsh: int, features?: array<int, string>, is_default?: bool}  $data
     */
    public function create(array $data, int $adminId): SubscriptionPlan
    {
        return DB::transaction(function () use ($data, $adminId) {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                $this->clearDefault();
            }

            $plan = SubscriptionPlan::create([
                'name' => $data['name'],
                'key' => $data['key'],
                'price' => $data['price'],
                'price_slsh' => $data['price_slsh'],
                'duration' => SubscriptionPlan::DURATION,
                'features' => array_values($data['features'] ?? []),
                'is_default' => $isDefault,
            ]);

            Log::info('Subscription plan created by admin', ['plan_id' => $plan->id, 'plan' => $plan->only(['key', 'name', 'price', 'price_slsh', 'is_default', 'features']), 'changed_by' => $adminId]);

            return $plan;
        });
    }

    /**
     * The key is left as it is: the app and existing subscriptions depend on it.
     *
     * @param  array{name: string, price: numeric, price_slsh: int, features?: array<int, string>, is_default?: bool}  $data
     */
    public function update(SubscriptionPlan $plan, array $data, int $adminId): SubscriptionPlan
    {
        return DB::transaction(function () use ($plan, $data, $adminId) {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($plan->is_default && ! $isDefault) {
                throw new ApiException('plan.default_required', 'Every merchant falls back to the default plan. Make another plan the default first.', 409);
            }

            if ($isDefault && ! $plan->is_default) {
                $this->clearDefault();
            }

            $before = $plan->only(['name', 'price', 'price_slsh', 'is_default', 'features']);

            $plan->update([
                'name' => $data['name'],
                'price' => $data['price'],
                'price_slsh' => $data['price_slsh'],
                'features' => array_values($data['features'] ?? []),
                'is_default' => $isDefault,
            ]);

            Log::info('Subscription plan updated by admin', [
                'plan_id' => $plan->id,
                'before' => $before,
                'after' => $plan->only(['name', 'price', 'price_slsh', 'is_default', 'features']),
                'changed_by' => $adminId,
            ]);

            return $plan;
        });
    }

    public function delete(SubscriptionPlan $plan, int $adminId): void
    {
        if ($plan->is_default) {
            throw new ApiException('plan.is_default', 'The default plan cannot be deleted. Make another plan the default first.', 409);
        }

        $inUse = $this->usage($plan);

        if ($inUse > 0) {
            throw new ApiException('plan.in_use', "This plan cannot be deleted: {$inUse} merchant subscription(s) or open payment(s) still use it.", 409);
        }

        $plan->delete();

        Log::info('Subscription plan deleted by admin', ['plan_id' => $plan->id, 'key' => $plan->key, 'changed_by' => $adminId]);
    }

    /**
     * Current or scheduled subscriptions and open payments on this plan.
     */
    public function usage(SubscriptionPlan $plan): int
    {
        $current = MerchantSubscription::where(fn ($query) => $query
            ->where('subscription_plan_id', $plan->id)
            ->where(fn ($period) => $period->whereNull('end_date')->orWhere('end_date', '>=', today())))
            ->orWhere('next_plan_id', $plan->id)
            ->count();

        $openPayments = Invoice::where('type', 'Subscription')
            ->where('subscription_plan_id', $plan->id)
            ->where('status', 'Pending')
            ->count();

        return $current + $openPayments;
    }

    private function clearDefault(): void
    {
        SubscriptionPlan::where('is_default', true)->update(['is_default' => false]);
    }
}
