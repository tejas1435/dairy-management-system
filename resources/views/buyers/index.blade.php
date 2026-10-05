@extends('layouts.app')

@section('title', __('buyers.title'))

@section('header')
    <x-page-header :title="__('buyers.title')" :subtitle="__('buyers.subtitle')">
        @can('create', App\Models\Buyer::class)
            <a href="{{ route('buyers.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('buyers.create_title') }}
            </a>
        @endcan
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('buyers.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="search" class="form-label small mb-1">{{ __('buyers.filters.search') }}</label>
                    <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label for="channel" class="form-label small mb-1">{{ __('buyers.filters.channel') }}</label>
                    <select id="channel" name="channel" class="form-select form-select-sm">
                        <option value="">{{ __('buyers.filters.all_channels') }}</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->id }}" @selected((int) ($filters['channel'] ?? 0) === $channel->id)>
                                {{ $channel->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="area" class="form-label small mb-1">{{ __('buyers.filters.area') }}</label>
                    <input type="text" id="area" name="area" value="{{ $filters['area'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
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
                <div class="col-6 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary flex-grow-1">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                    </button>
                    <a href="{{ route('buyers.index') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                </div>
            </form>
        </div>

        @if ($buyers->isEmpty())
            <div class="card-body">
                <x-empty-state icon="shop" :description="__('buyers.empty')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-sticky-head align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('buyers.columns.name') }}</th>
                            <th scope="col">{{ __('buyers.columns.channel') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('buyers.columns.mobile') }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">{{ __('buyers.columns.area') }}</th>
                            <th scope="col">{{ __('app.labels.status') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buyers as $buyer)
                            <tr>
                                <td>
                                    <a href="{{ route('buyers.show', $buyer) }}" class="fw-medium">{{ $buyer->name }}</a>
                                </td>
                                <td>
                                    <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
                                        {{ $buyer->salesChannel?->name }}
                                    </span>
                                </td>
                                <td class="d-none d-md-table-cell small">{{ $buyer->mobile ?: '—' }}</td>
                                <td class="d-none d-lg-table-cell small">{{ $buyer->area ?: '—' }}</td>
                                <td><x-status-badge :active="$buyer->is_active" /></td>
                                <td class="text-end text-nowrap">
                                    @can('update', $buyer)
                                        <a href="{{ route('buyers.edit', $buyer) }}"
                                           class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>

                                        <form method="POST" action="{{ route('buyers.status.update', $buyer) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $buyer->is_active ? 0 : 1 }}">
                                            <button type="submit"
                                                    class="btn btn-sm {{ $buyer->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $buyer->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($buyers->hasPages())
                <div class="card-body border-top">{{ $buyers->links() }}</div>
            @endif
        @endif
    </div>

    <div class="small text-body-secondary mt-3">
        <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('buyers.help.shared_identity') }}</p>
        <p class="mb-0"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>{{ __('buyers.help.permissions') }}</p>
    </div>
@endsection
