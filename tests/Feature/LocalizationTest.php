<?php

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Support\PermissionCatalog;
use Illuminate\Support\Arr;

beforeEach(function (): void {
    seedBusiness();
    seedAuthorization();
});

test('english is the default for a guest', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in');

    expect(app()->getLocale())->toBe('en');
});

test('a signed in user sees their persisted language', function (string $locale, string $expected) {
    $user = userWithPermissions(
        [PermissionCatalog::DASHBOARD_VIEW],
        ['locale' => $locale]
    );

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee($expected, escape: false);
})->with([
    'gujarati' => ['gu', 'ડેશબોર્ડ'],
    'hindi' => ['hi', 'डैशबोर्ड'],
    'english' => ['en', 'Dashboard'],
]);

test('gujarati and hindi render real translations on the sign in screen', function () {
    $this->withSession([SetLocale::SESSION_KEY => 'gu'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('સાઇન ઇન કરો', escape: false)
        ->assertDontSee('Sign in');

    $this->withSession([SetLocale::SESSION_KEY => 'hi'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('साइन इन करें', escape: false);
});

test('switching language persists on the user record', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW], ['locale' => 'en']);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'gu'])
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->locale)->toBe(Locale::Gujarati);

    // And it survives into a fresh request rather than living only in session.
    $this->actingAs($user->refresh())->get(route('dashboard'))
        ->assertSee('ડેશબોર્ડ', escape: false);
});

test('a guest can switch language before signing in', function () {
    $this->from(route('login'))
        ->post(route('locale.update'), ['locale' => 'hi'])
        ->assertRedirect(route('login'))
        ->assertSessionHas(SetLocale::SESSION_KEY, 'hi');

    $this->get(route('login'))->assertSee('साइन इन करें', escape: false);
});

test('an unsupported locale is rejected', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW], ['locale' => 'en']);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect($user->refresh()->locale)->toBe(Locale::English);
});

test('a stale or invalid session locale falls back to english instead of breaking', function () {
    // A hand-edited session or an old cookie must not make the app unreachable.
    $this->withSession([SetLocale::SESSION_KEY => 'xx'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in');
});

test('the user preference wins over the session value', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW], ['locale' => 'gu']);

    $this->actingAs($user)
        ->withSession([SetLocale::SESSION_KEY => 'hi'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('ડેશબોર્ડ', escape: false);
});

test('every locale defines the same translation keys', function (string $file) {
    $english = require base_path("lang/en/{$file}.php");

    foreach (['gu', 'hi'] as $locale) {
        $translated = require base_path("lang/{$locale}/{$file}.php");

        expect(array_keys(Arr::dot($translated)))
            ->toBe(array_keys(Arr::dot($english)), "lang/{$locale}/{$file}.php does not match English");
    }
})->with(['app', 'auth', 'passwords', 'nav', 'dashboard', 'profile', 'users', 'roles', 'settings', 'audit', 'buyers', 'customers', 'expenses', 'finance', 'milk', 'partners', 'pricing']);

test('no translated string is left as the english original', function (string $file) {
    // Guards against a locale file being copied from English and never
    // translated, which would pass a key-parity check but ship English text.
    $english = Arr::dot(require base_path("lang/en/{$file}.php"));

    foreach (['gu', 'hi'] as $locale) {
        $translated = Arr::dot(require base_path("lang/{$locale}/{$file}.php"));

        $identical = collect($translated)
            ->filter(fn ($value, $key) => is_string($value)
                && $value === ($english[$key] ?? null)
                /*
                 * Placeholders and brand-neutral tokens legitimately match. `SNF %`
                 * is one of them: Solids-Not-Fat is written in Latin letters on the
                 * collection slips themselves, in Gujarati and Hindi alike, so
                 * "translating" it would make the field harder to recognise than the
                 * abbreviation the operator is copying from.
                 */
                && ! in_array($value, [':name', 'INR', 'SNF %'], true)
                && ! preg_match('/^[\s:.\-]*$/', $value))
            ->keys();

        expect($identical->all())->toBe(
            [],
            "lang/{$locale}/{$file}.php still has English text at: ".$identical->implode(', ')
        );
    }
})->with(['app', 'auth', 'passwords', 'nav', 'dashboard', 'profile', 'users', 'roles', 'settings', 'audit', 'buyers', 'customers', 'expenses', 'finance', 'milk', 'partners', 'pricing']);
