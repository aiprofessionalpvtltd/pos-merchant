<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * For installs that already exist: adds the Setting admin module (payment fees), creates its
 * View/Create/Edit/Delete permissions, and gives them to the Super Admin and Admin roles.
 * Subscription plans use the existing Subscription permissions, which it also gives them.
 * A fresh install gets the module from ModuleSeeder and PermissionsSeeder.
 *
 *   php artisan db:seed --class=AdminSettingsPermissionsSeeder
 */
class AdminSettingsPermissionsSeeder extends Seeder
{
    private const MODULES = ['Setting', 'Subscription'];

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

        $this->command?->info('Setting and Subscription permissions ready: '.$permissions->pluck('name')->implode(', '));
    }
}
