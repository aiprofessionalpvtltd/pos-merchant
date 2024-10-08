<?php

namespace Database\Seeders;

use App\Models\POSPermission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class POSPermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $permissions = [
            'POS',
            'Inventory',
            'Transactions',
            'Reports',
            'Employee Management',
        ];

        foreach ($permissions as $permission) {
            POSPermission::create(['name' => $permission]);
        }
    }
}
