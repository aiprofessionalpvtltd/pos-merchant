<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
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
        ]);

        $this->createPersonalAccessClient();

    }

    /**
     * Create a personal access client for Passport.
     *
     * @return void
     */
    private function createPersonalAccessClient()
    {
        $clientRepository = new ClientRepository();
        $clientRepository->createPersonalAccessClient(
            null, 'POS', env('APP_URL')
        );

        $this->command->info('Personal access client created successfully.');
    }
}
