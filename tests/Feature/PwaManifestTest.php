<?php

use App\Http\Middleware\SetLocale;
use App\Support\PermissionCatalog;

beforeEach(function (): void {
    seedBusiness();
    seedAuthorization();
});

test('the manifest is served and is valid json', function () {
    $this->get(route('pwa.manifest'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');
});

test('the manifest carries the fields installability requires', function () {
    $manifest = $this->get(route('pwa.manifest'))->json();

    expect($manifest)
        ->toHaveKeys(['name', 'short_name', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'])
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/dashboard');

    expect($manifest['name'])->not->toBeEmpty();
    expect($manifest['short_name'])->not->toBeEmpty();
});

test('the manifest declares 192 and 512 icons including a maskable one', function () {
    $icons = collect($this->get(route('pwa.manifest'))->json('icons'));

    expect($icons->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and($icons->pluck('purpose')->all())->toContain('maskable');

    foreach ($icons as $icon) {
        expect($icon['type'])->toBe('image/png');
    }
});

test('every declared icon exists as a project owned file', function () {
    $icons = $this->get(route('pwa.manifest'))->json('icons');

    foreach ($icons as $icon) {
        $path = public_path(parse_url($icon['src'], PHP_URL_PATH));

        expect(file_exists($path))->toBeTrue("Missing icon file: {$icon['src']}");

        [$width, $height] = getimagesize($path);
        [$declaredWidth] = explode('x', $icon['sizes']);

        // A manifest that lies about its icon sizes fails installability.
        expect($width)->toBe((int) $declaredWidth)
            ->and($height)->toBe((int) $declaredWidth);
    }
});

test('no icon is loaded from a remote origin', function () {
    $icons = $this->get(route('pwa.manifest'))->json('icons');

    foreach ($icons as $icon) {
        expect($icon['src'])->not->toStartWith('http://cdn')
            ->and(parse_url($icon['src'], PHP_URL_HOST))->toBe(parse_url(config('app.url'), PHP_URL_HOST));
    }
});

test('the manifest follows the current locale', function () {
    $this->withSession([SetLocale::SESSION_KEY => 'gu']);

    expect($this->get(route('pwa.manifest'))->json('lang'))->toBe('gu');
});

test('pages link to the manifest and declare a theme colour', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('rel="manifest"', escape: false)
        ->assertSee('name="theme-color"', escape: false);

    $this->actingAs(userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('rel="manifest"', escape: false);
});

test('phase 1 does not claim offline capability it does not have', function () {
    /*
     * The service worker, caching strategy and installability hardening are
     * Phase 10. Registering an empty worker now would advertise offline support
     * that does not exist, so no registration script should be present and no
     * worker file should be served.
     */
    $html = $this->get(route('login'))->getContent();

    expect($html)
        ->not->toContain('serviceWorker')
        ->not->toContain('navigator.serviceWorker');

    expect(file_exists(public_path('sw.js')))->toBeFalse()
        ->and(file_exists(public_path('service-worker.js')))->toBeFalse();
});
