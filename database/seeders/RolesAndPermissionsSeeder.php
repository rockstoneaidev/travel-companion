<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotently syncs the spatie tables from the App\Enums\Role and
 * App\Enums\Permission enums (docs/ADMIN.md §3). Safe to re-run on deploy.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value);
        }

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value);
        }

        RoleModel::findByName(Role::Admin->value)->syncPermissions([
            Permission::AccessAdmin->value,
            Permission::ViewOps->value,
            Permission::ViewUsers->value,
            Permission::ViewActivity->value,
            Permission::ViewCosts->value,
        ]);

        // Superadmin holds no permissions directly: Gate::before in
        // AppServiceProvider grants it everything (docs/ADMIN.md §3.2).
    }
}
