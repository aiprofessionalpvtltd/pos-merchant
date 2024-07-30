<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'admin',
        ]);

        User::create([
            'name' => 'Tele Operator User',
            'email' => 'teleoperator@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'teleoperator',
        ]);

        User::create([
            'name' => 'Merchant User',
            'email' => 'merchant@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'merchant',
        ]);

        User::create([
            'name' => 'Mobile Operator User',
            'email' => 'mobileoperator@example.com',
            'password' => Hash::make('1234'),
            'user_type' => 'mobileoperator',
        ]);
    }
}
