{{-- Layout for the unauthenticated screens: sign in, forgot password, reset password. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="bg-body-tertiary">
    <div class="container py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="d-inline-flex align-items-center gap-2 fw-semibold">
                        <i class="bi bi-droplet-half text-primary fs-5" aria-hidden="true"></i>
                        {{ config('app.name') }}
                    </span>
                    {{-- Guests can switch language before signing in. --}}
                    <x-language-switcher />
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h5 mb-1">@yield('heading')</h1>
                        @hasSection('subheading')
                            <p class="text-body-secondary small mb-4">@yield('subheading')</p>
                        @else
                            <div class="mb-4"></div>
                        @endif

                        <x-alerts />

                        @yield('content')
                    </div>
                </div>

                @hasSection('below-card')
                    <div class="text-center small text-body-secondary mt-3">@yield('below-card')</div>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
