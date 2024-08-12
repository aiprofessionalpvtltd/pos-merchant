<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role; // Import the Role model
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = Module::all();

        $permissions = array(
            'Create',
            'View',
            'Edit',
            'Delete',
        );

        // Create permissions
        foreach ($modules as $module) {
            foreach ($permissions as $permission) {
                $permissionName = $permission . ' ' . $module->name;
                Permission::create([
                    'short_name' => $permissionName,
                    'name' => str_replace(' ', '-', strtolower($permissionName)),
                    'module' => $module->id
                ]);
            }
        }

        // Get roles
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        $adminRole = Role::where('name', 'Admin')->first();

        // Get all permissions
        $allPermissions = Permission::all();


        // Assign all permissions to both roles
        if ($superAdminRole) {
            $superAdminRole->givePermissionTo($allPermissions);
        }

        if ($adminRole) {
            $adminRole->givePermissionTo($allPermissions);
        }


    }
}
