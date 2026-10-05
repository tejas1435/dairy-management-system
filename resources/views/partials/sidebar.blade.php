{{--
    Main navigation.

    Only modules that exist are listed. Future phases contribute nothing here: a
    link to a "coming soon" page is fake functionality, and a disabled item that
    never becomes enabled is noise. Each phase adds its own entries when its
    routes are real.

    Visibility is permission-aware for tidiness, not for security. Every route
    behind these links is protected server-side.
--}}
@php
    use App\Support\BuyerPermissions;
    use App\Support\PermissionCatalog;

    $user = auth()->user();

    $canAccounts = $user?->can('finance.account.manage');
    $canCashbook = $user?->can('finance.cashbook.view');
    $canPartners = $user?->can('partner.view');
    $canExpenses = $user?->can('expense.view');
    $showFinance = $canAccounts || $canCashbook || $canPartners || $canExpenses;

    $canProduction = $user?->can('milk.production.view');
    $canDailyEntry = $user?->can('milk.customer_delivery.view');
    $canChannelSales = $user?->can('milk.sale.view');
    $canUsage = $user?->can('milk.usage.view');
    $canAdjustments = $user?->can('milk.adjustment.create');
    $canReconciliation = $user?->can('milk.reconciliation.view');
    $showMilk = $canProduction || $canDailyEntry || $canChannelSales
        || $canUsage || $canAdjustments || $canReconciliation;

    // Phase 5 masters. A Mandali and a vendor are buyers in their own channels, so
    // their permissions come from those families rather than a shared one.
    $canMandalis = $user?->can('mandali.view');
    $canVendors = $user?->can('vendor.view');

    $canCustomers = $user?->can('customer.view');

    $canBuyers = collect(BuyerPermissions::allViewPermissions())
        ->contains(fn (string $permission): bool => (bool) $user?->can($permission));

    $canUsers = $user?->can(PermissionCatalog::USER_MANAGE);
    $canRoles = $user?->can(PermissionCatalog::ROLE_MANAGE);
    $canAudit = $user?->can(PermissionCatalog::AUDIT_VIEW);
    $showAdmin = $canUsers || $canRoles || $canAudit;

    $canSettings = $user?->can(PermissionCatalog::SETTINGS_MANAGE);
@endphp

<ul class="nav flex-column app-nav py-2">
    @can(PermissionCatalog::DASHBOARD_VIEW)
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
               href="{{ route('dashboard') }}"
               @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                <span>{{ __('nav.dashboard') }}</span>
            </a>
        </li>
    @endcan

    {{--
        Customers. The Daily Entry grid is listed under Milk rather than here: it is
        a milk-distribution screen that happens to be organised by customer, and the
        operator filling it in every morning is doing the milk round, not customer
        administration.
    --}}
    @if ($canCustomers || $canMandalis || $canVendors)
        <li class="app-nav-heading">{{ __('nav.customers') }}</li>

        @if ($canCustomers)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}"
                   href="{{ route('customers.index') }}">
                    <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
                    <span>{{ __('nav.direct_customers') }}</span>
                </a>
            </li>
        @endif

        {{--
            Mandalis and vendors sit beside direct customers because they are the same
            kind of record — a buyer in a different channel — and somebody looking for
            "who we sell to" looks in one place.
        --}}
        @if ($canMandalis)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('mandalis.*') ? 'active' : '' }}"
                   href="{{ route('mandalis.index') }}">
                    <i class="bi bi-building" aria-hidden="true"></i>
                    <span>{{ __('nav.mandalis') }}</span>
                </a>
            </li>
        @endif

        @if ($canVendors)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('vendors.*') ? 'active' : '' }}"
                   href="{{ route('vendors.index') }}">
                    <i class="bi bi-shop" aria-hidden="true"></i>
                    <span>{{ __('nav.vendors') }}</span>
                </a>
            </li>
        @endif

        {{--
            Buyers in channels the business created. Behind `customer.view` because
            that is the family a custom channel resolves to (D26), and listed
            unconditionally within it: whether any such channel exists is data, and
            the list says so itself rather than disappearing.
        --}}
        @if ($canCustomers)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('other-buyers.*') ? 'active' : '' }}"
                   href="{{ route('other-buyers.index') }}">
                    <i class="bi bi-basket2" aria-hidden="true"></i>
                    <span>{{ __('nav.other_buyers') }}</span>
                </a>
            </li>
        @endif
    @endif

    @if ($showMilk)
        <li class="app-nav-heading">{{ __('nav.milk') }}</li>

        @if ($canProduction)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.production.*') ? 'active' : '' }}"
                   href="{{ route('milk.production.index') }}">
                    <i class="bi bi-droplet-half" aria-hidden="true"></i>
                    <span>{{ __('nav.production') }}</span>
                </a>
            </li>
        @endif

        @if ($canDailyEntry)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.customer-entry.*') ? 'active' : '' }}"
                   href="{{ route('milk.customer-entry.index') }}">
                    <i class="bi bi-table" aria-hidden="true"></i>
                    <span>{{ __('nav.customer_entry') }}</span>
                </a>
            </li>
        @endif

        @if ($canChannelSales)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.mandali-deliveries.*') ? 'active' : '' }}"
                   href="{{ route('milk.mandali-deliveries.index') }}">
                    <i class="bi bi-truck" aria-hidden="true"></i>
                    <span>{{ __('nav.mandali_deliveries') }}</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.vendor-sales.*') ? 'active' : '' }}"
                   href="{{ route('milk.vendor-sales.index') }}">
                    <i class="bi bi-shop-window" aria-hidden="true"></i>
                    <span>{{ __('nav.vendor_sales') }}</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.other-sales.*') ? 'active' : '' }}"
                   href="{{ route('milk.other-sales.index') }}">
                    <i class="bi bi-basket" aria-hidden="true"></i>
                    <span>{{ __('nav.other_sales') }}</span>
                </a>
            </li>
        @endif

        @if ($canUsage)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.usage.*') ? 'active' : '' }}"
                   href="{{ route('milk.usage.index') }}">
                    <i class="bi bi-cup-straw" aria-hidden="true"></i>
                    <span>{{ __('nav.milk_usage') }}</span>
                </a>
            </li>
        @endif

        @if ($canReconciliation)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.reconciliation') ? 'active' : '' }}"
                   href="{{ route('milk.reconciliation') }}">
                    <i class="bi bi-clipboard-data" aria-hidden="true"></i>
                    <span>{{ __('nav.reconciliation') }}</span>
                </a>
            </li>
        @endif

        {{--
            Adjustments are the authorised exception route, so the link appears
            only for someone who may actually record one. Everyone else has no
            reason to know the screen exists.
        --}}
        @if ($canAdjustments)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('milk.adjustments.*') ? 'active' : '' }}"
                   href="{{ route('milk.adjustments.index') }}">
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <span>{{ __('nav.milk_adjustments') }}</span>
                </a>
            </li>
        @endif
    @endif

    @if ($showFinance)
        <li class="app-nav-heading">{{ __('nav.finance') }}</li>

        @if ($canExpenses)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('finance.expenses.*') ? 'active' : '' }}"
                   href="{{ route('finance.expenses.index') }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    <span>{{ __('nav.expenses') }}</span>
                </a>
            </li>
        @endif

        @if ($canPartners)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('finance.partners.*') ? 'active' : '' }}"
                   href="{{ route('finance.partners.index') }}">
                    <i class="bi bi-people-fill" aria-hidden="true"></i>
                    <span>{{ __('nav.partners') }}</span>
                </a>
            </li>
        @endif

        @if ($canAccounts)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('finance.accounts.*') ? 'active' : '' }}"
                   href="{{ route('finance.accounts.index') }}">
                    <i class="bi bi-wallet2" aria-hidden="true"></i>
                    <span>{{ __('nav.accounts') }}</span>
                </a>
            </li>
        @endif

        @if ($canCashbook)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('finance.cashbook') ? 'active' : '' }}"
                   href="{{ route('finance.cashbook') }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i>
                    <span>{{ __('nav.cashbook') }}</span>
                </a>
            </li>
        @endif
    @endif

    @if ($canBuyers)
        <li class="app-nav-heading">{{ __('nav.masters') }}</li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('buyers.*') ? 'active' : '' }}"
               href="{{ route('buyers.index') }}">
                <i class="bi bi-shop" aria-hidden="true"></i>
                <span>{{ __('nav.buyers') }}</span>
            </a>
        </li>
    @endif

    @if ($showAdmin)
        <li class="app-nav-heading">{{ __('nav.administration') }}</li>

        @if ($canUsers)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                   href="{{ route('admin.users.index') }}">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <span>{{ __('nav.users') }}</span>
                </a>
            </li>
        @endif

        @if ($canRoles)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}"
                   href="{{ route('admin.roles.index') }}">
                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
                    <span>{{ __('nav.roles') }}</span>
                </a>
            </li>
        @endif

        @if ($canAudit)
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}"
                   href="{{ route('admin.audit.index') }}">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>{{ __('nav.audit') }}</span>
                </a>
            </li>
        @endif
    @endif

    <li class="app-nav-heading">{{ __('nav.settings') }}</li>

    @if ($canSettings)
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('settings.business.*') ? 'active' : '' }}"
               href="{{ route('settings.business.edit') }}">
                <i class="bi bi-building" aria-hidden="true"></i>
                <span>{{ __('nav.business') }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('settings.farms.*') ? 'active' : '' }}"
               href="{{ route('settings.farms.index') }}">
                <i class="bi bi-house-gear" aria-hidden="true"></i>
                <span>{{ __('nav.farm') }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('settings.sales-channels.*') ? 'active' : '' }}"
               href="{{ route('settings.sales-channels.index') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i>
                <span>{{ __('nav.sales_channels') }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('settings.milk-prices.*') ? 'active' : '' }}"
               href="{{ route('settings.milk-prices.index') }}">
                <i class="bi bi-tag" aria-hidden="true"></i>
                <span>{{ __('nav.milk_prices') }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('settings.expense-categories.*') ? 'active' : '' }}"
               href="{{ route('settings.expense-categories.index') }}">
                <i class="bi bi-tags" aria-hidden="true"></i>
                <span>{{ __('nav.expense_categories') }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('settings.payment-methods.*') ? 'active' : '' }}"
               href="{{ route('settings.payment-methods.index') }}">
                <i class="bi bi-credit-card" aria-hidden="true"></i>
                <span>{{ __('nav.payment_methods') }}</span>
            </a>
        </li>
    @endif

    {{-- Profile carries the language preference, so it needs no permission. --}}
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}"
           href="{{ route('profile.edit') }}">
            <i class="bi bi-person-gear" aria-hidden="true"></i>
            <span>{{ __('nav.profile') }}</span>
        </a>
    </li>
</ul>
