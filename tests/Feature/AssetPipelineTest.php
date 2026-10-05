<?php

use App\Models\User;

/*
 * The application serves Bootstrap, Bootstrap Icons and Chart.js from the Vite
 * build rather than a CDN (MASTER_SPEC section 3). These tests fail if the
 * manifest is missing, if an entry point is renamed, or if a CDN <link> or
 * <script> is reintroduced.
 *
 * The sign-in page is used as the sample because it is the only page a guest
 * can reach, and it renders the same head partial as the authenticated shell.
 */

test('the sign in page renders', function () {
    $this->get(route('login'))->assertOk();
});

test('pages load their assets from the vite manifest', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);

    expect($manifest)->toHaveKeys(['resources/scss/app.scss', 'resources/js/app.js']);

    $this->get(route('login'))
        ->assertSee($manifest['resources/scss/app.scss']['file'], escape: false)
        ->assertSee($manifest['resources/js/app.js']['file'], escape: false);
});

test('no stylesheet or script is loaded from a cdn', function () {
    $html = $this->get(route('login'))->getContent();

    expect($html)->not->toMatch('~<(link|script)[^>]+https?://(?!localhost)[^"\']+~i');
});

test('no tailwind class names reach the rendered page', function () {
    // Laravel's paginator emits Tailwind markup unless told otherwise, which is
    // the most likely way Tailwind could reappear after Phase 0 removed it.
    seedBusiness();

    $user = superAdmin();

    foreach (range(1, 25) as $index) {
        User::factory()->create();
    }

    $html = $this->actingAs($user)->get(route('admin.users.index'))->getContent();

    expect($html)
        ->toContain('pagination')
        ->not->toContain('relative inline-flex items-center');
});
