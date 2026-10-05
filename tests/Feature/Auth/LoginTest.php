<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedBusiness();
});

test('the sign in screen renders', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('auth.login.submit'));
});

test('there is no registration route', function () {
    // The absence of self-service sign-up is a product requirement, not an
    // oversight, so it is asserted rather than assumed.
    expect(app('router')->getRoutes()->getByName('register'))->toBeNull();

    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

test('an active user can sign in', function () {
    $user = User::factory()->create([
        'email' => 'owner@example.test',
        'password' => Hash::make('correct-horse-7'),
        'is_active' => true,
    ]);

    $this->post(route('login'), [
        'email' => 'owner@example.test',
        'password' => 'correct-horse-7',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('signing in records the time of the sign in', function () {
    $user = User::factory()->create([
        'password' => Hash::make('correct-horse-7'),
        'last_login_at' => null,
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-7',
    ]);

    expect($user->refresh()->last_login_at)->not->toBeNull();
});

test('the session id changes on sign in, so a fixed session cannot be reused', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-7')]);

    $this->get(route('login'));
    $before = session()->getId();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-7',
    ]);

    expect(session()->getId())->not->toBe($before);
});

test('a wrong password is rejected', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-7')]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an unknown email is rejected', function () {
    $this->post(route('login'), [
        'email' => 'nobody@example.test',
        'password' => 'whatever-123',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an inactive user cannot sign in even with the correct password', function () {
    $user = User::factory()->inactive()->create([
        'password' => Hash::make('correct-horse-7'),
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-7',
    ])->assertSessionHasErrors(['email' => __('auth.deactivated')]);

    $this->assertGuest();
});

test('sign in is rate limited after five failures', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-7')]);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-7',
    ]);

    // The sixth attempt is refused even though the password is now correct.
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain(__('auth.throttle', [
        'seconds' => RateLimiter::availableIn(
            Str::transliterate(Str::lower($user->email).'|127.0.0.1')
        ),
        'minutes' => 1,
    ]));
    $this->assertGuest();
});

test('a signed in user can sign out and the session is invalidated', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user);
    $sessionId = session()->getId();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionId);
});

test('guests are redirected to sign in when reaching a protected page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the root url sends guests to sign in and signed in users to the dashboard', function () {
    $this->get('/')->assertRedirect(route('login'));

    $this->actingAs(userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]));

    $this->get('/')->assertRedirect(route('dashboard'));
});
