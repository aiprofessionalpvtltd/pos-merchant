<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roles = array (
            'Super Admin',
            'Admin',
            'Tele Operator',
            'Mobile Operator',
            'Merchant',
        );

        foreach ($roles as $row) {

            $roleCreated =  Role::create([
                'name' => $row,
            ]);

            if($row == 'Super Admin'){
                $roleCreated->givePermissionTo(Permission::all());
            }
        }
    }
}
