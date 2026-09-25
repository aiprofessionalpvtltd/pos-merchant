<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $modules = [
            'Users',
            'Roles',
            'Merchant',
            'Invoice',
            'Transaction',
            'Order',
            'Subscription',
            'Product',
            'Cart',
            'Employee',
            'Setting',
        ];

        foreach ($modules as $row) {
            Module::firstOrCreate([
                'name' => $row,
            ]);
        }
    }
}
