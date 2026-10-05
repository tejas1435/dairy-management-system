@extends('layouts.app')

@section('title', __('partners.title'))

@section('header')
    <x-page-header :title="__('partners.title')" :subtitle="__('partners.subtitle')">
        @can('partner.create')
            <a href="{{ route('finance.partners.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('partners.create_title') }}
            </a>
        @endcan
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('finance.partners.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="search" class="form-label small mb-1">{{ __('app.actions.search') }}</label>
                    <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-8 col-md-4">
                    <label for="status" class="form-label small mb-1">{{ __('app.labels.status') }}</label>
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
                <div class="col-4 col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary flex-grow-1">
                        {{ __('app.actions.filter') }}
                    </button>
                    <a href="{{ route('finance.partners.index') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                </div>
            </form>
        </div>

        @if ($partners->isEmpty())
            <div class="card-body">
                <x-empty-state icon="people-fill" :description="__('partners.empty')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('partners.columns.name') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('partners.columns.mobile') }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">{{ __('partners.columns.joining_date') }}</th>
                            @can('partner.finance.view')
                                <th scope="col" class="text-end">{{ __('partners.columns.total') }}</th>
                            @endcan
                            <th scope="col">{{ __('app.labels.status') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($partners as $partner)
                            <tr>
                                <td>
                                    <a href="{{ route('finance.partners.show', $partner) }}" class="fw-medium">
                                        {{ $partner->name }}
                                    </a>
                                    @if ($partner->email)
                                        <div class="small text-body-secondary">{{ $partner->email }}</div>
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell small">{{ $partner->mobile ?: '—' }}</td>
                                <td class="d-none d-lg-table-cell small">
                                    {{ $partner->joining_date?->translatedFormat('d-m-Y') ?: '—' }}
                                </td>
                                @can('partner.finance.view')
                                    <td class="text-end fw-medium">
                                        <x-money :amount="$totals[$partner->id] ?? '0.00'" />
                                    </td>
                                @endcan
                                <td><x-status-badge :active="$partner->is_active" /></td>
                                <td class="text-end text-nowrap">
                                    @can('partner.update')
                                        <a href="{{ route('finance.partners.edit', $partner) }}"
                                           class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>

                                        <form method="POST" action="{{ route('finance.partners.status.update', $partner) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $partner->is_active ? 0 : 1 }}">
                                            <button type="submit"
                                                    class="btn btn-sm {{ $partner->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $partner->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($partners->hasPages())
                <div class="card-body border-top">{{ $partners->links() }}</div>
            @endif
        @endif
    </div>

    <div class="small text-body-secondary mt-3">
        <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('partners.help.unlimited') }}</p>
        <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('partners.help.derived_total') }}</p>
        <p class="mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('partners.help.no_profit_share') }}</p>
    </div>
@endsection
