<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The web app manifest.
 *
 * Served from a route rather than a static file so the application name and
 * start URL come from configuration instead of being duplicated, and so the
 * manifest can be covered by a test.
 *
 * Phase 1 provides the manifest and metadata only. There is deliberately no
 * service worker: registering an empty one would make the application look
 * installable and offline-capable while providing neither. The service worker,
 * caching strategy and installability hardening are Phase 10 (docs/PWA.md).
 */
class PwaManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => __('app.short_name'),
            'description' => __('app.description'),
            'lang' => app()->getLocale(),
            'dir' => 'ltr',
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => '#0f766e',
            'background_color' => '#f8f9fa',
            'icons' => [
                [
                    'src' => asset('icons/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icons/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icons/icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json');
    }
}
