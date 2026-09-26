<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class MerchantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $merchant = $this->createMerchant(
                phoneNumber: '+252656486734',
                pin: '1234',
                firstName: 'Demo',
                lastName: 'Merchant',
            );

            $employees = [
                ['phone_number' => '+252656486735', 'pin' => '6735', 'role' => 'POS Employee'],
                ['phone_number' => '+252656486736', 'pin' => '6736', 'role' => 'Inventory Employee'],
                ['phone_number' => '+252656486737', 'pin' => '6737', 'role' => 'Transaction Employee'],
                ['phone_number' => '+252656486738', 'pin' => '6738', 'role' => 'Reporting Employee'],
                ['phone_number' => '+252656486739', 'pin' => '6739', 'role' => 'Employee Manager'],
            ];

            foreach ($employees as $employeeData) {
                $this->createEmployee($merchant, $employeeData['phone_number'], $employeeData['pin'], $employeeData['role']);
            }
        });
    }

    private function createMerchant(string $phoneNumber, string $pin, string $firstName, string $lastName): Merchant
    {
        $user = User::updateOrCreate(
            ['email' => $phoneNumber.'@email.com'],
            [
                'name' => $firstName.' '.$lastName,
                'password' => Hash::make($pin),
                'pin' => $pin,
                'user_type' => 'merchant',
            ]
        );
        $user->assignRole(Role::where('name', 'Merchant')->first());

        return Merchant::updateOrCreate(
            ['phone_number' => $phoneNumber],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'business_name' => $firstName.' '.$lastName.' Shop',
                'merchant_code' => 'MER-DEMO-0001',
                'is_approved' => true,
                'confirmation_status' => true,
                'user_id' => $user->id,
            ]
        );
    }

    private function createEmployee(Merchant $merchant, string $phoneNumber, string $pin, string $role): Employee
    {
        $user = User::updateOrCreate(
            ['email' => $phoneNumber.'@email.com'],
            [
                'name' => $role,
                'password' => Hash::make($pin),
                'pin' => $pin,
                'user_type' => 'employee',
            ]
        );

        return Employee::updateOrCreate(
            ['phone_number' => $phoneNumber, 'shop_id' => $merchant->id],
            [
                'first_name' => $role,
                'last_name' => 'Demo',
                'dob' => '1995-01-01',
                'location' => $merchant->location ?? 'Mogadishu',
                'role' => $role,
                'salary' => 0,
                'status' => 'active',
                'user_id' => $user->id,
            ]
        );
    }
}
