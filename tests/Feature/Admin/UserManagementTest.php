<?php

use App\Enums\Locale;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    seedBusiness();
    seedAuthorization();
});

test('a user without user.manage is refused, not merely shown no menu item', function (string $routeName) {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->get(route($routeName))->assertForbidden();
})->with([
    'admin.users.index',
    'admin.users.create',
]);

test('a user without user.manage cannot create a user by posting directly', function () {
    // Hiding the link is presentation. The server must refuse the write.
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->post(route('admin.users.store'), [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.test',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
        'locale' => 'en',
    ])->assertForbidden();

    expect(User::query()->where('email', 'sneaky@example.test')->exists())->toBeFalse();
});

test('an authorised user can see the user list', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    User::factory()->create(['name' => 'Ramesh Patel']);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Ramesh Patel');
});

test('the list can be filtered by search term and status', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    User::factory()->create(['name' => 'Active Anil', 'is_active' => true]);
    User::factory()->create(['name' => 'Retired Rekha', 'is_active' => false]);

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Rekha']))
        ->assertOk()
        ->assertSee('Retired Rekha')
        ->assertDontSee('Active Anil');

    $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertSee('Retired Rekha')
        ->assertDontSee('Active Anil');
});

test('an authorised user can create a user with roles', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Nita Shah',
        'email' => 'nita@example.test',
        'password' => 'strong-pass-99',
        'password_confirmation' => 'strong-pass-99',
        'locale' => Locale::Gujarati->value,
        'is_active' => '1',
        'roles' => [RoleCatalog::ACCOUNTANT],
    ])->assertRedirect(route('admin.users.index'));

    $created = User::query()->where('email', 'nita@example.test')->firstOrFail();

    expect($created->name)->toBe('Nita Shah')
        ->and($created->locale)->toBe(Locale::Gujarati)
        ->and($created->is_active)->toBeTrue()
        ->and($created->hasRole(RoleCatalog::ACCOUNTANT))->toBeTrue();
});

test('the password is hashed, never stored as given', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Hash Check',
        'email' => 'hash@example.test',
        'password' => 'strong-pass-99',
        'password_confirmation' => 'strong-pass-99',
        'locale' => 'en',
    ]);

    $created = User::query()->where('email', 'hash@example.test')->firstOrFail();

    expect($created->password)->not->toBe('strong-pass-99')
        ->and(Hash::check('strong-pass-99', $created->password))->toBeTrue();
});

test('creating a user validates its input', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    $existing = User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => '',
        'email' => 'taken@example.test',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
        'locale' => 'fr',
        'roles' => ['Nonexistent Role'],
    ])->assertSessionHasErrors(['name', 'email', 'password', 'locale', 'roles.0']);
});

test('editing a user can change roles and clear old ones', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    $target = User::factory()->create();
    $target->assignRole(RoleCatalog::VIEWER);

    $this->actingAs($admin)->put(route('admin.users.update', $target), [
        'name' => $target->name,
        'email' => $target->email,
        'locale' => 'en',
        'is_active' => '1',
        'roles' => [RoleCatalog::MANAGER],
    ])->assertRedirect(route('admin.users.index'));

    $target->refresh();

    expect($target->hasRole(RoleCatalog::MANAGER))->toBeTrue()
        ->and($target->hasRole(RoleCatalog::VIEWER))->toBeFalse();
});

test('leaving the password blank on edit keeps the existing password', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    $target = User::factory()->create(['password' => Hash::make('original-pass1')]);

    $this->actingAs($admin)->put(route('admin.users.update', $target), [
        'name' => 'Renamed',
        'email' => $target->email,
        'locale' => 'en',
        'is_active' => '1',
        'password' => '',
    ])->assertRedirect(route('admin.users.index'));

    expect(Hash::check('original-pass1', $target->refresh()->password))->toBeTrue()
        ->and($target->name)->toBe('Renamed');
});

test('an administrator can deactivate and reactivate another user', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    $target = User::factory()->create(['is_active' => true]);

    $this->actingAs($admin)->put(route('admin.users.status.update', $target), ['is_active' => 0])
        ->assertRedirect();
    expect($target->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->put(route('admin.users.status.update', $target), ['is_active' => 1])
        ->assertRedirect();
    expect($target->refresh()->is_active)->toBeTrue();
});

test('an administrator cannot deactivate their own account', function () {
    // Guards against a single form submission locking everyone out.
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);

    $this->actingAs($admin)->put(route('admin.users.status.update', $admin), ['is_active' => 0])
        ->assertSessionHasErrors('is_active');

    expect($admin->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->put(route('admin.users.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'locale' => 'en',
    ])->assertSessionHasErrors('is_active');

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('there is no route for deleting a user', function () {
    // History must stay attributable, so accounts are deactivated instead.
    expect(app('router')->getRoutes()->getByName('admin.users.destroy'))->toBeNull();
});

test('password hashes are never rendered into the management screens', function () {
    $admin = userWithPermissions([PermissionCatalog::USER_MANAGE]);
    $target = User::factory()->create(['password' => Hash::make('original-pass1')]);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertDontSee($target->password);

    $this->actingAs($admin)->get(route('admin.users.edit', $target))
        ->assertOk()
        ->assertDontSee($target->password);
});
