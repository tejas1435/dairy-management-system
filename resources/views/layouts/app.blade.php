{{--
    Authenticated application shell.

    Sidebar is fixed on large screens and a Bootstrap offcanvas below that, so
    the same navigation partial serves both without duplication. Desktop is the
    primary environment, so the layout favours density over decoration.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="bg-body-tertiary">
    <a href="#main-content" class="visually-hidden-focusable app-skip-link">
        {{ __('nav.skip_to_content') }}
    </a>

    {{-- Offcanvas navigation for tablet and phone. --}}
    <div class="offcanvas offcanvas-start app-sidebar text-bg-dark d-lg-none" tabindex="-1"
         id="appSidebarOffcanvas" aria-label="{{ __('nav.main_navigation') }}">
        <div class="offcanvas-header border-bottom border-secondary-subtle">
            <span class="offcanvas-title fw-semibold d-inline-flex align-items-center gap-2">
                <i class="bi bi-droplet-half" aria-hidden="true"></i>
                {{ config('app.name') }}
            </span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                    aria-label="{{ __('app.actions.close') }}"></button>
        </div>
        <div class="offcanvas-body p-0">
            @include('partials.sidebar')
        </div>
    </div>

    {{-- Fixed sidebar for desktop. --}}
    <aside class="app-sidebar app-sidebar-fixed text-bg-dark d-none d-lg-flex flex-column">
        <div class="app-sidebar-brand d-flex align-items-center gap-2 px-3">
            <i class="bi bi-droplet-half fs-5" aria-hidden="true"></i>
            <span class="fw-semibold text-truncate">{{ config('app.name') }}</span>
        </div>
        <nav class="flex-grow-1 overflow-auto" aria-label="{{ __('nav.main_navigation') }}">
            @include('partials.sidebar')
        </nav>
    </aside>

    <div class="app-content">
        @include('partials.topbar')

        <main id="main-content" class="container-fluid px-3 px-lg-4 py-4">
            @yield('header')

            <x-alerts />

            @yield('content')
        </main>
    </div>
</body>
</html>
