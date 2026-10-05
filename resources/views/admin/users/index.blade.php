@extends('layouts.app')

@section('title', __('users.title'))

@section('header')
    <x-page-header :title="__('users.title')" :subtitle="__('users.subtitle')">
        <a href="{{ route('admin.users.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('users.create_title') }}
        </a>
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label for="search" class="form-label small mb-1">{{ __('users.filters.search') }}</label>
                    <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label for="status" class="form-label small mb-1">{{ __('users.filters.status') }}</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">{{ __('app.status.all') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>
                            {{ __('app.status.active') }}
                        </option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>
                            {{ __('app.status.inactive') }}
                        </option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="role" class="form-label small mb-1">{{ __('users.filters.role') }}</label>
                    <select id="role" name="role" class="form-select form-select-sm">
                        <option value="">{{ __('users.filters.all_roles') }}</option>
                        @foreach ($roles as $roleName)
                            <option value="{{ $roleName }}" @selected(($filters['role'] ?? '') === $roleName)>
                                {{ $roleName }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-secondary flex-grow-1">
                        {{ __('app.actions.filter') }}
                    </button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">
                        {{ __('app.actions.clear') }}
                    </a>
                </div>
            </form>
        </div>

        @if ($users->isEmpty())
            <div class="card-body">
                <x-empty-state icon="people" :description="__('users.empty')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-sticky-head align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('users.columns.name') }}</th>
                            <th scope="col">{{ __('users.columns.roles') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('users.columns.language') }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">{{ __('users.columns.last_login') }}</th>
                            <th scope="col">{{ __('users.columns.status') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $listedUser)
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $listedUser->name }}</div>
                                    <div class="small text-body-secondary">{{ $listedUser->email }}</div>
                                </td>
                                <td>
                                    @forelse ($listedUser->roles as $role)
                                        <span class="badge text-primary-emphasis bg-primary-subtle border border-primary-subtle">
                                            {{ $role->name }}
                                        </span>
                                    @empty
                                        <span class="small text-body-secondary">{{ __('app.status.none') }}</span>
                                    @endforelse
                                </td>
                                <td class="d-none d-md-table-cell small">
                                    {{ $listedUser->preferredLocale()->nativeName() }}
                                </td>
                                <td class="d-none d-lg-table-cell small text-body-secondary">
                                    {{ $listedUser->last_login_at?->translatedFormat('d-m-Y H:i') ?? __('app.status.never') }}
                                </td>
                                <td><x-status-badge :active="$listedUser->is_active" /></td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.users.edit', $listedUser) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        {{ __('app.actions.edit') }}
                                    </a>

                                    @unless ($listedUser->is($currentUser = auth()->user()))
                                        <form method="POST" action="{{ route('admin.users.status.update', $listedUser) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $listedUser->is_active ? 0 : 1 }}">
                                            <button type="submit"
                                                    class="btn btn-sm {{ $listedUser->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $listedUser->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                            </button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="card-body border-top">
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </div>

    <p class="small text-body-secondary mt-3 mb-0">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('users.help.no_delete') }}
    </p>
@endsection
