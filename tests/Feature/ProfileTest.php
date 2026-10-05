<?php

use App\Enums\Locale;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    seedBusiness();
    seedAuthorization();
});

test('the profile page is available to any signed in user, with or without permissions', function () {
    $this->actingAs(userWithoutPermissions())->get(route('profile.edit'))->assertOk();
});

test('a user can update their own name, email and language', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW], ['locale' => 'en']);

    $this->actingAs($user)->put(route('profile.update'), [
        'name' => 'Updated Name',
        'email' => 'updated@example.test',
        'locale' => Locale::Hindi->value,
    ])->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('updated@example.test')
        ->and($user->locale)->toBe(Locale::Hindi);
});

test('changing the email clears any prior verification of it', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    expect($user->email_verified_at)->not->toBeNull();

    $this->actingAs($user)->put(route('profile.update'), [
        'name' => $user->name,
        'email' => 'new-address@example.test',
        'locale' => 'en',
    ]);

    expect($user->refresh()->email_verified_at)->toBeNull();
});

test('the email must stay unique', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($user)->put(route('profile.update'), [
        'name' => $user->name,
        'email' => 'taken@example.test',
        'locale' => 'en',
    ])->assertSessionHasErrors('email');
});

test('a user cannot change their own roles through the profile form', function () {
    // The request never reads a roles field, so submitting one achieves nothing.
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    $rolesBefore = $user->roles->pluck('name')->sort()->values()->all();

    $this->actingAs($user)->put(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'roles' => [RoleCatalog::SUPER_ADMIN],
    ])->assertRedirect(route('profile.edit'));

    expect($user->refresh()->roles->pluck('name')->sort()->values()->all())->toBe($rolesBefore)
        ->and($user->hasRole(RoleCatalog::SUPER_ADMIN))->toBeFalse();
});

test('a user cannot change their own active status through the profile form', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->put(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'is_active' => '0',
    ])->assertRedirect(route('profile.edit'));

    expect($user->refresh()->is_active)->toBeTrue();
});

test('a user cannot reassign themselves to another business', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    $businessBefore = $user->business_id;

    $this->actingAs($user)->put(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'business_id' => 999,
    ]);

    expect($user->refresh()->business_id)->toBe($businessBefore);
});

test('changing password requires the current password', function () {
    $user = userWithPermissions(
        [PermissionCatalog::DASHBOARD_VIEW],
        ['password' => Hash::make('current-pass-1')]
    );

    $this->actingAs($user)->put(route('profile.password.update'), [
        'current_password' => 'wrong-password',
        'password' => 'brand-new-pass9',
        'password_confirmation' => 'brand-new-pass9',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('current-pass-1', $user->refresh()->password))->toBeTrue();
});

test('a valid password change is hashed and takes effect', function () {
    $user = userWithPermissions(
        [PermissionCatalog::DASHBOARD_VIEW],
        ['password' => Hash::make('current-pass-1')]
    );

    $this->actingAs($user)->put(route('profile.password.update'), [
        'current_password' => 'current-pass-1',
        'password' => 'brand-new-pass9',
        'password_confirmation' => 'brand-new-pass9',
    ])->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->password)->not->toBe('brand-new-pass9')
        ->and(Hash::check('brand-new-pass9', $user->password))->toBeTrue();
});

test('the new password must be confirmed and strong enough', function () {
    $user = userWithPermissions(
        [PermissionCatalog::DASHBOARD_VIEW],
        ['password' => Hash::make('current-pass-1')]
    );

    $this->actingAs($user)->put(route('profile.password.update'), [
        'current_password' => 'current-pass-1',
        'password' => 'weak',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
});

test('changing the password ends this users other sessions', function () {
    /*
     * The suite runs on the array session driver for speed, but this behaviour
     * only applies to the database driver the application actually uses, so the
     * driver is set explicitly here rather than leaving the branch untested.
     */
    config(['session.driver' => 'database']);

    $user = userWithPermissions(
        [PermissionCatalog::DASHBOARD_VIEW],
        ['password' => Hash::make('current-pass-1')]
    );

    // A session that belongs to this user from another browser.
    DB::table('sessions')->insert([
        'id' => 'other-device-session',
        'user_id' => $user->getKey(),
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Other device',
        'payload' => base64_encode('x'),
        'last_activity' => now()->timestamp,
    ]);

    // And one belonging to somebody else, which must survive.
    $other = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'unrelated-session',
        'user_id' => $other->getKey(),
        'ip_address' => '10.0.0.2',
        'user_agent' => 'Unrelated',
        'payload' => base64_encode('x'),
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)->put(route('profile.password.update'), [
        'current_password' => 'current-pass-1',
        'password' => 'brand-new-pass9',
        'password_confirmation' => 'brand-new-pass9',
    ]);

    expect(DB::table('sessions')->where('id', 'other-device-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'unrelated-session')->exists())->toBeTrue();
});
