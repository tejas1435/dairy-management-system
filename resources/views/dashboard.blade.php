@extends('layouts.app')

@section('title', __('dashboard.title'))

@section('header')
    <x-page-header :title="__('dashboard.welcome', ['name' => $user->name])" />
@endsection

@section('content')
    {{--
        Phase 1 shows only data that exists: the business, its primary farm and
        the signed-in account. No milk, revenue, expense or animal figures appear
        anywhere on this page, and there are no charts, because inventing them
        would make an empty system look populated.
    --}}
    <div class="alert alert-light border d-flex align-items-start gap-2 small" role="note">
        <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ __('dashboard.foundation_notice') }}</div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 text-body-secondary text-uppercase small mb-3">
                        <i class="bi bi-building me-1" aria-hidden="true"></i>
                        {{ __('dashboard.business.heading') }}
                    </h3>

                    @if ($business)
                        <dl class="row row-cols-1 mb-0 small">
                            <div class="col d-flex justify-content-between border-bottom py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('dashboard.business.name') }}</dt>
                                <dd class="mb-0 fw-medium text-end">{{ $business->name }}</dd>
                            </div>
                            <div class="col d-flex justify-content-between border-bottom py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('dashboard.business.currency') }}</dt>
                                <dd class="mb-0 fw-medium text-end">{{ $business->currency }}</dd>
                            </div>
                            <div class="col d-flex justify-content-between border-bottom py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('dashboard.business.timezone') }}</dt>
                                <dd class="mb-0 fw-medium text-end">{{ $business->timezone }}</dd>
                            </div>
                            <div class="col d-flex justify-content-between py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('dashboard.business.date_format') }}</dt>
                                <dd class="mb-0 fw-medium text-end">{{ $business->date_format->label() }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-body-secondary small mb-0">{{ __('dashboard.business.not_configured') }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 text-body-secondary text-uppercase small mb-3">
                        <i class="bi bi-house-gear me-1" aria-hidden="true"></i>
                        {{ __('dashboard.farm.heading') }}
                    </h3>

                    @if ($primaryFarm)
                        <dl class="row row-cols-1 mb-0 small">
                            <div class="col d-flex justify-content-between border-bottom py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('dashboard.farm.name') }}</dt>
                                <dd class="mb-0 fw-medium text-end">{{ $primaryFarm->name }}</dd>
                            </div>
                            <div class="col d-flex justify-content-between border-bottom py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('dashboard.farm.code') }}</dt>
                                <dd class="mb-0 fw-medium text-end"><code>{{ $primaryFarm->code }}</code></dd>
                            </div>
                            <div class="col d-flex justify-content-between py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('app.labels.status') }}</dt>
                                <dd class="mb-0 text-end"><x-status-badge :active="$primaryFarm->is_active" /></dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-body-secondary small mb-0">{{ __('dashboard.farm.not_configured') }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 text-body-secondary text-uppercase small mb-3">
                        <i class="bi bi-person-badge me-1" aria-hidden="true"></i>
                        {{ __('dashboard.account.heading') }}
                    </h3>

                    <dl class="row row-cols-1 mb-0 small">
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('dashboard.account.name') }}</dt>
                            <dd class="mb-0 fw-medium text-end">{{ $user->name }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('dashboard.account.email') }}</dt>
                            <dd class="mb-0 fw-medium text-end text-truncate">{{ $user->email }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('dashboard.account.language') }}</dt>
                            <dd class="mb-0 fw-medium text-end">{{ $user->preferredLocale()->nativeName() }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('dashboard.account.roles') }}</dt>
                            <dd class="mb-0 text-end">
                                @forelse ($user->roles as $role)
                                    <span class="badge text-primary-emphasis bg-primary-subtle border border-primary-subtle">{{ $role->name }}</span>
                                @empty
                                    <span class="text-body-secondary">{{ __('dashboard.account.no_roles') }}</span>
                                @endforelse
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 text-body-secondary text-uppercase small mb-3">
                        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
                        {{ __('dashboard.quick_links.heading') }}
                    </h3>

                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('profile.edit') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-person-gear me-1" aria-hidden="true"></i>
                            {{ __('dashboard.quick_links.profile') }}
                        </a>

                        @can(\App\Support\PermissionCatalog::USER_MANAGE)
                            <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-people me-1" aria-hidden="true"></i>
                                {{ __('dashboard.quick_links.users') }}
                            </a>
                        @endcan

                        @can(\App\Support\PermissionCatalog::ROLE_MANAGE)
                            <a href="{{ route('admin.roles.index') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                                {{ __('dashboard.quick_links.roles') }}
                            </a>
                        @endcan

                        @can(\App\Support\PermissionCatalog::SETTINGS_MANAGE)
                            <a href="{{ route('settings.business.edit') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-building me-1" aria-hidden="true"></i>
                                {{ __('dashboard.quick_links.business') }}
                            </a>
                            <a href="{{ route('settings.farms.index') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-house-gear me-1" aria-hidden="true"></i>
                                {{ __('dashboard.quick_links.farm') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
