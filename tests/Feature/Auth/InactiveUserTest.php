<?php

use App\Support\PermissionCatalog;

/*
 * Checking is_active at sign-in is not enough. An administrator who deactivates
 * somebody who is already signed in must cut off the session they already hold,
 * which is what the 'active' middleware does on every authenticated request.
 */

beforeEach(function (): void {
    seedBusiness();
});

test('a user deactivated mid session loses access on the next request', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    // An administrator deactivates the account while the session is live.
    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.deactivated')]);

    $this->assertGuest();
});

test('the deactivated session is destroyed, not merely redirected', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'));
    $this->assertGuest();

    // A second attempt behaves like any other guest request.
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('every authenticated area enforces the active check', function (string $routeName) {
    $user = superAdmin();

    $this->actingAs($user);
    $user->forceFill(['is_active' => false])->save();

    $this->get(route($routeName))->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'dashboard',
    'profile.edit',
    'admin.users.index',
    'admin.roles.index',
    'settings.business.edit',
    'settings.farms.index',
]);

test('a deactivated user cannot reach the profile page either', function () {
    $user = userWithoutPermissions(['is_active' => true]);

    $this->actingAs($user);
    $this->get(route('profile.edit'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});
