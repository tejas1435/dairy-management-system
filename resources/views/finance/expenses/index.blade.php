@extends('layouts.app')

@section('title', __('expenses.title'))

@section('header')
    <x-page-header :title="__('expenses.title')" :subtitle="__('expenses.subtitle')">
        @can('expense.create')
            <a href="{{ route('finance.expenses.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('expenses.create_title') }}
            </a>
        @endcan
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('finance.expenses.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="search" class="form-label small mb-1">{{ __('app.actions.search') }}</label>
                    <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label for="category" class="form-label small mb-1">{{ __('expenses.columns.category') }}</label>
                    <select id="category" name="category" class="form-select form-select-sm">
                        <option value="">{{ __('app.status.all') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) ($filters['category'] ?? 0) === $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="status" class="form-label small mb-1">{{ __('app.labels.status') }}</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">{{ __('app.status.all') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>
                            {{ __('finance.statuses.active') }}
                        </option>
                        <option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>
                            {{ __('finance.statuses.cancelled') }}
                        </option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="from" class="form-label small mb-1">{{ __('audit.filters.from') }}</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2 d-flex gap-1">
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                           class="form-control form-control-sm" aria-label="{{ __('audit.filters.to') }}">
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                    </button>
                </div>
            </form>
        </div>

        <div class="card-body border-bottom py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-body-secondary">{{ __('expenses.totals.note') }}</div>
            <div class="text-end">
                <div class="small text-body-secondary">{{ __('expenses.totals.active') }}</div>
                <div class="fw-semibold font-monospace">&#8377;{{ $activeTotal }}</div>
            </div>
        </div>

        @if ($expenses->isEmpty())
            <div class="card-body">
                <x-empty-state icon="receipt" :description="__('expenses.empty')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-sticky-head align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('expenses.columns.date') }}</th>
                            <th scope="col">{{ __('expenses.columns.description') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('expenses.columns.category') }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">{{ __('expenses.columns.funding') }}</th>
                            <th scope="col" class="text-end">{{ __('expenses.columns.amount') }}</th>
                            <th scope="col">{{ __('app.labels.status') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expenses as $expense)
                            <tr class="{{ $expense->isCancelled() ? 'opacity-75' : '' }}">
                                <td class="small text-nowrap">{{ $expense->expense_date->translatedFormat('d-m-Y') }}</td>
                                <td>
                                    <a href="{{ route('finance.expenses.show', $expense) }}" class="fw-medium">
                                        {{ $expense->description }}
                                    </a>
                                    @if ($expense->payee_name)
                                        <div class="small text-body-secondary">{{ $expense->payee_name }}</div>
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell small">{{ $expense->category?->name }}</td>
                                <td class="d-none d-lg-table-cell small text-body-secondary">
                                    <i class="bi bi-diagram-2 me-1" aria-hidden="true"></i>{{ $expense->fundingAllocations->count() }}
                                </td>
                                <td class="text-end fw-medium">
                                    <x-money :amount="$expense->amount" :muted="$expense->isCancelled()" />
                                </td>
                                <td>
                                    <x-status-badge :active="! $expense->isCancelled()"
                                                    :active-label="__('finance.statuses.active')"
                                                    :inactive-label="__('finance.statuses.cancelled')" />
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('finance.expenses.show', $expense) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($expenses->hasPages())
                <div class="card-body border-top">{{ $expenses->links() }}</div>
            @endif
        @endif
    </div>

    <p class="small text-body-secondary mt-3 mb-0">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('expenses.help.one_expense') }}
    </p>
@endsection
