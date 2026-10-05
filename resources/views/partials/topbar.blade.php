{{--
    Top navigation: context title, language switcher, account menu.

    The notification bell is intentionally absent. It is a structural element of
    the shell, but an icon that opens nothing is worse than no icon, so Phase 8
    adds it together with the notification module it belongs to.
--}}
<header class="app-topbar bg-body border-bottom sticky-top">
    <div class="d-flex align-items-center gap-2 px-3 px-lg-4 h-100">
        <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#appSidebarOffcanvas"
                aria-controls="appSidebarOffcanvas" aria-label="{{ __('nav.toggle_sidebar') }}">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <div class="me-auto min-w-0">
            <h1 class="h6 mb-0 text-truncate">@yield('page-title', __('nav.dashboard'))</h1>
            @hasSection('page-context')
                <div class="small text-body-secondary text-truncate">@yield('page-context')</div>
            @endif
        </div>

        <x-language-switcher />

        <div class="dropdown">
            <button class="btn btn-sm btn-light border d-inline-flex align-items-center gap-2 dropdown-toggle"
                    type="button" data-bs-toggle="dropdown" aria-expanded="false"
                    aria-label="{{ __('nav.user_menu') }}">
                <span class="app-avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
                <span class="d-none d-sm-inline text-truncate app-topbar-username">
                    {{ auth()->user()->name }}
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li>
                    <span class="dropdown-item-text small text-body-secondary">
                        {{ auth()->user()->email }}
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="{{ route('profile.edit') }}">
                        <i class="bi bi-person-gear me-2" aria-hidden="true"></i>{{ __('nav.profile') }}
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>{{ __('auth.logout') }}
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
