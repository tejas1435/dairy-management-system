<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    seedBusiness();
    Notification::fake();
});

test('the forgot password screen renders', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee(__('auth.forgot.submit'));
});

test('an active user receives a reset link', function () {
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', __('passwords.sent_generic'));

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the response does not reveal whether an email has an account', function () {
    $user = User::factory()->create();

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

    // Identical confirmation either way, so the form cannot be used to
    // enumerate which addresses exist.
    expect($known->getSession()->get('status'))
        ->toBe($unknown->getSession()->get('status'))
        ->toBe(__('passwords.sent_generic'));

    Notification::assertNothingSentTo(User::factory()->make(['email' => 'nobody@example.test']));
});

test('a deactivated user is never sent a reset link', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', __('passwords.sent_generic'));

    Notification::assertNotSentTo($user, ResetPassword::class);
});

test('an active user can complete a reset and sign in with the new password', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
    $token = Password::broker()->createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-pass9',
        'password_confirmation' => 'brand-new-pass9',
    ])->assertRedirect(route('login'));

    expect(Hash::check('brand-new-pass9', $user->refresh()->password))->toBeTrue();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'brand-new-pass9',
    ])->assertRedirect(route('dashboard'));
});

test('a deactivated user cannot regain access with a valid token', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-1')]);

    // The token is issued while the account is still active.
    $token = Password::broker()->createToken($user);
    $user->forceFill(['is_active' => false])->save();

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-pass9',
        'password_confirmation' => 'brand-new-pass9',
    ])->assertSessionHasErrors(['email' => __('auth.deactivated')]);

    // The password is unchanged, so the old one still does not help them either.
    expect(Hash::check('brand-new-pass9', $user->refresh()->password))->toBeFalse();
    $this->assertGuest();
});

test('an invalid token is rejected', function () {
    $user = User::factory()->create();

    $this->post(route('password.store'), [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'brand-new-pass9',
        'password_confirmation' => 'brand-new-pass9',
    ])->assertSessionHasErrors('email');
});

test('the new password must be confirmed and meet the strength rule', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
});
