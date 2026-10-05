<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission catalogue.
 *
 * Idempotent: existing permissions are left alone, missing ones are created.
 * Permissions an administrator added by hand are never removed, because this
 * seeder only adds what the catalogue defines.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Permissions synced: %d in catalogue, %d in database.',
            count(PermissionCatalog::all()),
            Permission::query()->count(),
        ));
    }
}
