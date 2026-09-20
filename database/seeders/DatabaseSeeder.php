<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
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
            POSPermissionsTableSeeder::class,
            MerchantSeeder::class,
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
