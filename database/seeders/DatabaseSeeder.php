<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            ModuleSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            SettingSeeder::class,
            PermissionsSeeder::class,
            SubscriptionPlanSeeder::class,
            // Gives the plans their keys, features, SLSH prices and the default plan the v1 API needs.
            PlanCatalogueSeeder::class,
            POSPermissionsTableSeeder::class,
        ]);

        $this->runPermissionUpdateCommand();

    }

    /**
     * Run the custom Artisan command to update permissions.
     *
     * @return void
     */
    private function runPermissionUpdateCommand()
    {
        Artisan::call('permission:update');
        $this->command->info('Permissions updated successfully.');
    }
}
