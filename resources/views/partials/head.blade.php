{{--
    Shared document head.

    The PWA manifest and metadata land here in Phase 1. There is deliberately no
    service worker registration: the manifest alone makes the application
    describable and partly installable, whereas an empty service worker would
    advertise offline capability the application does not have. Phase 10 adds the
    real worker and caching strategy (docs/PWA.md).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>@hasSection('title')@yield('title') &middot; @endif{{ config('app.name') }}</title>

<meta name="description" content="{{ __('app.description') }}">
<meta name="theme-color" content="#0f766e">
<meta name="application-name" content="{{ config('app.name') }}">

<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">

{{-- Installed-app metadata for iOS, which does not read the manifest. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ __('app.short_name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">

@vite(['resources/scss/app.scss', 'resources/js/app.js'])
