<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * For installs that already exist: adds the Product, Cart and Employee admin modules, creates their
 * View/Create/Edit/Delete permissions, and gives them to the Super Admin and Admin roles.
 * A fresh install gets all of this from ModuleSeeder and PermissionsSeeder.
 *
 *   php artisan db:seed --class=AdminInventoryPermissionsSeeder
 */
class AdminInventoryPermissionsSeeder extends Seeder
{
    private const MODULES = ['Product', 'Cart', 'Employee'];

    public function run(): void
    {
        foreach (self::MODULES as $name) {
            Module::firstOrCreate(['name' => $name]);
        }

        Artisan::call('permission:update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modules = Module::whereIn('name', self::MODULES)->pluck('id');
        $permissions = Permission::whereIn('module', $modules)->get();

        foreach (['Super Admin', 'Admin'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo($permissions);
        }

        $this->command?->info('Product and Cart permissions ready: '.$permissions->pluck('name')->implode(', '));
    }
}
