<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'admin',
        ]);
        $user->assignRole(Role::where('name', 'Admin')->first());

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'super_admin',
        ]);

        $user->assignRole(Role::where('name', 'Super Admin')->first());

        $user = User::create([
            'name' => 'Tele Operator',
            'email' => 'teleoperator@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'teleoperator',
        ]);

        $user->assignRole(Role::where('name', 'Tele Operator')->first());

        $user = User::create([
            'name' => 'Mobile Operator',
            'email' => 'mobileoperator@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'mobileoperator',
        ]);

        $user->assignRole(Role::where('name', 'Mobile Operator')->first());

    }
}
