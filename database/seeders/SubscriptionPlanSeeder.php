<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SubscriptionPlan::insert([
            [
                'name' => 'Gold Package',
                'price' => 10.00,
                'duration' => 'monthly',
            ],
            [
                'name' => 'Silver Package',
                'price' => 5.00,
                'duration' => 'monthly',
            ],
        ]);
    }
}
