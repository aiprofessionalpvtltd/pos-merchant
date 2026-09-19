<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Reference data for the v1 plan catalogue. Safe to re-run: rows are matched by `key`.
 */
class PlanCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'silver' => [
                'name' => 'Silver Package',
                'price' => 0,
                'price_slsh' => 0,
                'duration' => 'monthly',
                'is_default' => true,
                'features' => ['dashboard.basic', 'inventory.read', 'payments.request'],
            ],
            'gold' => [
                'name' => 'Gold Package',
                'price' => 11.50,
                'price_slsh' => 92000,
                'duration' => 'monthly',
                'is_default' => false,
                'features' => [
                    'dashboard.full', 'pos.register', 'pos.scanning', 'inventory.write',
                    'inventory.transfers', 'employees.manage', 'reports.full', 'nfc.payments', 'offline.mode',
                ],
            ],
        ];

        foreach ($plans as $key => $attributes) {
            $plan = SubscriptionPlan::withTrashed()->where('key', $key)->first()
                ?? SubscriptionPlan::withTrashed()->where('name', $attributes['name'])->first()
                ?? new SubscriptionPlan;

            $plan->fill(['key' => $key] + $attributes);
            $plan->save();

            if ($plan->trashed()) {
                $plan->restore();
            }
        }
    }
}
