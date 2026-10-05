<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the default roles and their starting permissions.
 *
 * Idempotent, with one deliberate asymmetry:
 *
 * - Super Admin is re-synced to the whole catalogue on every run, so a later
 *   phase that adds permissions does not leave the top role unable to use them.
 * - Every other role is only given its starting permissions when the role is
 *   first created. Re-running the seeder must not undo an administrator's
 *   deliberate changes to Owner, Manager and the rest.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleCatalog::systemRoles() as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            $isNew = $role === null;

            $role ??= Role::create(['name' => $name, 'guard_name' => 'web']);

            if (RoleCatalog::isAllPermissions($name)) {
                $role->syncPermissions(PermissionCatalog::all());

                continue;
            }

            if ($isNew) {
                $role->syncPermissions(RoleCatalog::permissionsFor($name));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Roles synced: '.implode(', ', RoleCatalog::systemRoles()).'.');
    }
}
