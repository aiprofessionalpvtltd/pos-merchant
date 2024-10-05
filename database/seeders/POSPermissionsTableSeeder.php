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
            'inventory',
            'POS',
            'employee overview',
            'statistic and reports',
            'profile',
            'transaction overview & history',
        ];

        foreach ($permissions as $permission) {
            POSPermission::create(['name' => $permission]);
        }
    }
}
