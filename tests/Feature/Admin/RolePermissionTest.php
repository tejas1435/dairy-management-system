<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    seedBusiness();
    seedAuthorization();
});

test('the whole permission catalogue is seeded', function () {
    expect(Permission::query()->count())->toBe(count(PermissionCatalog::all()));

    foreach (PermissionCatalog::all() as $permission) {
        expect(Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists())
            ->toBeTrue("Missing permission: {$permission}");
    }
});

test('the catalogue contains the permissions the specification names', function () {
    expect(PermissionCatalog::all())
        ->toContain('dashboard.view')
        ->toContain('milk.customer_delivery.override_rate')
        ->toContain('milk.adjustment.create')
        ->toContain('mandali.settlement.manage')
        ->toContain('animal.purchase.create')
        ->toContain('finance.account.manage')
        ->toContain('partner.contribution.create')
        ->toContain('employee.document.manage')
        ->toContain('report.export')
        ->toContain('user.manage')
        ->toContain('role.manage')
        ->toContain('permission.manage')
        ->toContain('settings.manage')
        ->toContain('audit.view');
});

test('every default role is seeded', function () {
    foreach (RoleCatalog::systemRoles() as $role) {
        expect(Role::query()->where('name', $role)->where('guard_name', 'web')->exists())
            ->toBeTrue("Missing role: {$role}");
    }
});

test('super admin holds every permission in the catalogue', function () {
    $role = Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->firstOrFail();

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionCatalog::all())->sort()->values()->all());
});

test('viewer receives only view permissions and no write or manage permissions', function () {
    $granted = Role::query()->where('name', RoleCatalog::VIEWER)->firstOrFail()
        ->permissions->pluck('name');

    expect($granted)->not->toBeEmpty();

    $nonView = $granted->reject(fn (string $name): bool => str_ends_with($name, '.view'))->values();

    expect($nonView->all())->toBe([], 'Viewer holds a non-view permission: '.$nonView->implode(', '));
});

test('viewer cannot see financial or personnel-sensitive data', function () {
    $granted = Role::query()->where('name', RoleCatalog::VIEWER)->firstOrFail()
        ->permissions->pluck('name')->all();

    // Read-only is not the same as "may read everything".
    expect($granted)
        ->not->toContain('finance.view')
        ->not->toContain('finance.cashbook.view')
        ->not->toContain('partner.finance.view')
        ->not->toContain('employee.document.view')
        ->not->toContain('employee.salary.view')
        ->not->toContain('report.financial.view')
        ->not->toContain('audit.view');
});

test('no role except super admin receives account administration', function () {
    foreach (RoleCatalog::systemRoles() as $name) {
        if ($name === RoleCatalog::SUPER_ADMIN) {
            continue;
        }

        $granted = RoleCatalog::permissionsFor($name);

        expect($granted)
            ->not->toContain('user.manage', "{$name} should not manage users")
            ->not->toContain('role.manage', "{$name} should not manage roles")
            ->not->toContain('permission.manage', "{$name} should not manage permissions");
    }
});

test('operational roles are not given money permissions', function () {
    $manager = RoleCatalog::permissionsFor(RoleCatalog::MANAGER);
    $operator = RoleCatalog::permissionsFor(RoleCatalog::DATA_OPERATOR);

    foreach ([$manager, $operator] as $granted) {
        expect($granted)
            ->not->toContain('finance.view')
            ->not->toContain('expense.create')
            ->not->toContain('customer.payment.create')
            ->not->toContain('milk.adjustment.create')
            ->not->toContain('milk.customer_delivery.override_rate')
            ->not->toContain('milk.sale.cancel');
    }
});

test('the seeder is idempotent', function () {
    $permissions = Permission::query()->count();
    $roles = Role::query()->count();

    seedAuthorization();
    seedAuthorization();

    expect(Permission::query()->count())->toBe($permissions)
        ->and(Role::query()->count())->toBe($roles);
});

test('re-seeding does not undo an administrators edits to a non super admin role', function () {
    $role = Role::query()->where('name', RoleCatalog::VIEWER)->firstOrFail();
    $role->syncPermissions(['dashboard.view']);

    seedAuthorization();

    expect($role->refresh()->permissions->pluck('name')->all())->toBe(['dashboard.view']);
});

test('a user without role.manage is refused', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.roles.store'), ['name' => 'Hacked'])->assertForbidden();

    expect(Role::query()->where('name', 'Hacked')->exists())->toBeFalse();
});

test('an authorised user can create a custom role with permissions', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE]);

    $this->actingAs($admin)->post(route('admin.roles.store'), [
        'name' => 'Milk Recorder',
        'permissions' => ['dashboard.view', 'milk.production.create'],
    ])->assertRedirect(route('admin.roles.index'));

    $role = Role::query()->where('name', 'Milk Recorder')->firstOrFail();

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['dashboard.view', 'milk.production.create']);
});

test('changing a role permission changes authorisation on the next request', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE, PermissionCatalog::USER_MANAGE]);

    $role = Role::findOrCreate('Settings Only', 'web');
    $role->syncPermissions([PermissionCatalog::SETTINGS_MANAGE]);

    $member = User::factory()->create();
    $member->assignRole($role);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($member)->get(route('settings.business.edit'))->assertOk();

    // Revoke through the UI, exactly as an administrator would.
    $this->actingAs($admin)->put(route('admin.roles.update', $role), [
        'name' => 'Settings Only',
        'permissions' => [],
    ])->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($member->refresh())->get(route('settings.business.edit'))->assertForbidden();
});

test('a system role cannot be renamed', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE]);
    $role = Role::query()->where('name', RoleCatalog::MANAGER)->firstOrFail();

    $this->actingAs($admin)->put(route('admin.roles.update', $role), [
        'name' => 'Renamed Manager',
        'permissions' => ['dashboard.view'],
    ])->assertSessionHasErrors('name');

    expect($role->refresh()->name)->toBe(RoleCatalog::MANAGER);
});

test('a system role can still have its permissions edited', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE]);
    $role = Role::query()->where('name', RoleCatalog::MANAGER)->firstOrFail();

    $this->actingAs($admin)->put(route('admin.roles.update', $role), [
        'name' => RoleCatalog::MANAGER,
        'permissions' => ['dashboard.view', 'animal.view'],
    ])->assertRedirect(route('admin.roles.index'));

    expect($role->refresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['animal.view', 'dashboard.view']);
});

test('super admin permissions cannot be edited away', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE]);
    $role = Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->firstOrFail();

    $this->actingAs($admin)->put(route('admin.roles.update', $role), [
        'name' => RoleCatalog::SUPER_ADMIN,
        'permissions' => ['dashboard.view'],
    ])->assertRedirect();

    expect($role->refresh()->permissions)->toHaveCount(count(PermissionCatalog::all()));
});

test('a system role cannot be deleted', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE]);
    $role = Role::query()->where('name', RoleCatalog::VIEWER)->firstOrFail();

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))
        ->assertSessionHasErrors('role');

    expect(Role::query()->where('name', RoleCatalog::VIEWER)->exists())->toBeTrue();
});

test('a custom role in use cannot be deleted until its users are reassigned', function () {
    $admin = userWithPermissions([PermissionCatalog::ROLE_MANAGE]);
    $role = Role::findOrCreate('Temporary', 'web');

    $member = User::factory()->create();
    $member->assignRole($role);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))
        ->assertSessionHasErrors('role');
    expect(Role::query()->where('name', 'Temporary')->exists())->toBeTrue();

    $member->removeRole($role);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertRedirect();
    expect(Role::query()->where('name', 'Temporary')->exists())->toBeFalse();
});

test('super admin holds every permission, granted through its role', function () {
    $user = superAdmin();

    foreach (PermissionCatalog::all() as $permission) {
        expect($user->can($permission))->toBeTrue("Super Admin denied {$permission}");
    }
});

test('super admin can reach every permission protected page', function (string $routeName) {
    seedBusiness();

    $this->actingAs(superAdmin())->get(route($routeName))->assertOk();
})->with([
    'dashboard',
    'admin.users.index',
    'admin.users.create',
    'admin.roles.index',
    'admin.roles.create',
    'settings.business.edit',
    'settings.farms.index',
    'settings.farms.create',
]);

test('super admin access comes from its permissions, not from its name', function () {
    /*
     * Regression guard for the removal of the Gate::before role-name bypass.
     *
     * The permissions are stripped directly here; the role editor deliberately
     * refuses to do this. If any authorisation path still short-circuited on the
     * role name, the account would keep its access and this test would fail.
     */
    $user = superAdmin();

    Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->firstOrFail()
        ->syncPermissions([]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->refresh()->can(PermissionCatalog::USER_MANAGE))->toBeFalse()
        ->and($user->can(PermissionCatalog::DASHBOARD_VIEW))->toBeFalse();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});

test('no authorisation decision anywhere is made from a role name', function () {
    // A role is a bundle of permissions, never a shortcut. Nothing in the
    // application may branch on hasRole() or a literal role name to decide
    // access; RoleCatalog is referenced only to protect seeded role records and
    // to seed their bundles.
    $sources = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    expect($sources)->not->toBeEmpty();

    $offenders = [];

    foreach ($sources as $path => $contents) {
        // Strip comments so the explanatory note about why there is no bypass
        // does not itself trip the check.
        $code = implode('', array_map(
            fn (array|string $token): string => match (true) {
                is_string($token) => $token,
                in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) => '',
                default => $token[1],
            },
            token_get_all($contents)
        ));

        foreach (['Gate::before', 'hasRole(', 'hasAnyRole(', 'hasAllRoles('] as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)." uses {$needle}";
            }
        }
    }

    expect($offenders)->toBe([], 'Role-name authorisation found: '.implode('; ', $offenders));
});

test('a user with no roles can sign in but reach nothing', function () {
    $user = userWithoutPermissions();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('settings.business.edit'))->assertForbidden();

    // Self-service remains available: they can at least fix their profile.
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Administrator-created permissions are not Super Admin capabilities
|--------------------------------------------------------------------------
|
| Super Admin holds everything because the seeder grants it the whole
| catalogue -- and the catalogue only. A permission an administrator invents
| must not become a Super Admin capability just by existing, or "create a
| permission" would quietly be "grant yourself a capability".
*/

test('a permission created by an administrator is not granted to Super Admin', function () {
    Permission::findOrCreate('warehouse.manage', 'web');

    (new RoleSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdmin = Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->firstOrFail();

    expect($superAdmin->hasPermissionTo('warehouse.manage'))->toBeFalse()
        // ...while the seeded catalogue is still held in full.
        ->and($superAdmin->permissions)->toHaveCount(count(PermissionCatalog::all()));
});

test('re-seeding resets Super Admin to the catalogue, dropping a custom grant', function () {
    /*
     * Documented in docs/PERMISSIONS.md so it does not surprise anyone: the
     * sync is authoritative, so a custom permission granted to Super Admin
     * directly does not survive a deploy. Grant it through a custom role
     * instead. The alternative -- adopting every permission found in the
     * database -- would make any created permission a Super Admin capability,
     * which is the worse failure.
     */
    $custom = Permission::findOrCreate('warehouse.manage', 'web');
    $superAdmin = Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->firstOrFail();

    $superAdmin->givePermissionTo($custom);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($superAdmin->fresh()->hasPermissionTo('warehouse.manage'))->toBeTrue();

    (new RoleSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($superAdmin->fresh()->hasPermissionTo('warehouse.manage'))->toBeFalse();
});

test('re-seeding does not undo an administrator edit to any other role', function () {
    // The asymmetry is deliberate: only Super Admin is re-synced. Owner and the
    // rest are seeded once, so a deploy must not silently restore a permission
    // an administrator deliberately removed.
    $owner = Role::query()->where('name', RoleCatalog::OWNER)->firstOrFail();

    $owner->revokePermissionTo(PermissionCatalog::SETTINGS_MANAGE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (new RoleSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($owner->fresh()->hasPermissionTo(PermissionCatalog::SETTINGS_MANAGE))->toBeFalse();
});
