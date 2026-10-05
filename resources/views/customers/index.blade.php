@extends('layouts.app')

@section('title', __('customers.title'))

@section('header')
    <x-page-header :title="__('customers.title')" :subtitle="__('customers.subtitle')">
        @can('create', [App\Models\Buyer::class])
            <a href="{{ route('customers.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('customers.actions.add') }}
            </a>
        @endcan
    </x-page-header>
@endsection

@section('content')
    <form method="GET" action="{{ route('customers.index') }}" class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-4">
            <label for="search" class="form-label small mb-1">{{ __('customers.list.search') }}</label>
            <input type="search" class="form-control form-control-sm" id="search" name="search"
                   value="{{ $filters['search'] ?? '' }}">
        </div>
        <div class="col-6 col-md-2">
            <label for="area" class="form-label small mb-1">{{ __('customers.list.filter_area') }}</label>
            <input type="text" class="form-control form-control-sm" id="area" name="area"
                   value="{{ $filters['area'] ?? '' }}">
        </div>
        <div class="col-6 col-md-2">
            <label for="milk_type" class="form-label small mb-1">{{ __('customers.list.filter_milk') }}</label>
            <select class="form-select form-select-sm" id="milk_type" name="milk_type">
                <option value="">{{ __('app.status.all') }}</option>
                @foreach ($milkTypes as $milkType)
                    <option value="{{ $milkType->value }}" @selected(($filters['milk_type'] ?? null) === $milkType->value)>
                        {{ $milkType->label() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="status" class="form-label small mb-1">{{ __('customers.list.filter_status') }}</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <option value="">{{ __('app.status.all') }}</option>
                <option value="active" @selected(($filters['status'] ?? null) === 'active')>
                    {{ __('customers.status.active') }}
                </option>
                <option value="archived" @selected(($filters['status'] ?? null) === 'archived')>
                    {{ __('customers.status.archived') }}
                </option>
            </select>
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('app.actions.filter') }}</button>
            <a href="{{ route('customers.index') }}" class="btn btn-sm btn-outline-secondary">
                {{ __('app.actions.clear') }}
            </a>
        </div>
    </form>

    <div class="card shadow-sm">
        @if ($customers->isEmpty())
            <div class="card-body">
                <x-empty-state icon="person-lines-fill"
                               :title="request()->hasAny(['search', 'area', 'milk_type', 'status'])
                                   ? __('customers.list.none')
                                   : __('customers.list.none_yet')"
                               :description="__('customers.subtitle')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('customers.list.columns.customer') }}</th>
                            <th scope="col">{{ __('customers.fields.mobile') }}</th>
                            <th scope="col">{{ __('customers.list.columns.area') }}</th>
                            <th scope="col">{{ __('customers.list.columns.preferences') }}</th>
                            <th scope="col">{{ __('customers.fields.status') }}</th>
                            <th scope="col" class="text-end">{{ __('customers.list.columns.outstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            @php
                                $active = $customer->preferences->where('is_active', true);
                                $paused = $pausedToday[$customer->id] ?? false;
                            @endphp
                            <tr class="{{ $customer->is_active ? '' : 'text-body-secondary' }}">
                                <td>
                                    <a href="{{ route('customers.show', $customer) }}" class="fw-medium text-decoration-none">
                                        {{ $customer->name }}
                                    </a>
                                    @if ($customer->delivery_note)
                                        <div class="small text-body-secondary text-truncate" style="max-width: 22rem">
                                            <i class="bi bi-sticky me-1" aria-hidden="true"></i>{{ $customer->delivery_note }}
                                        </div>
                                    @endif
                                </td>
                                <td class="small font-monospace">{{ $customer->mobile ?? '—' }}</td>
                                <td class="small">{{ $customer->area ?? '—' }}</td>
                                <td class="small">
                                    @forelse ($active as $preference)
                                        <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
                                            {{ $preference->milk_type->label() }}
                                        </span>
                                    @empty
                                        <span class="text-body-secondary">—</span>
                                    @endforelse
                                </td>
                                <td>
                                    @if (! $customer->is_active)
                                        <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
                                            {{ __('customers.status.archived') }}
                                        </span>
                                    @elseif ($paused)
                                        <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                            {{ __('customers.list.paused_today') }}
                                        </span>
                                    @else
                                        <span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">
                                            {{ __('customers.status.active') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <x-money :amount="$outstanding[$customer->id] ?? '0'" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-body border-top">
                {{ $customers->links() }}
            </div>
        @endif
    </div>
@endsection
