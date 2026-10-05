@extends('layouts.app')

@section('title', __('expenses.detail_title'))

@section('header')
    <x-page-header :title="$expense->description" :subtitle="$expense->category?->name"
                   :back="route('finance.expenses.index')">
        @if (! $expense->isCancelled())
            @can('expense.update')
                <a href="{{ route('finance.expenses.edit', $expense) }}" class="btn btn-sm btn-outline-secondary">
                    {{ __('app.actions.edit') }}
                </a>
            @endcan
            @can('expense.cancel')
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#cancelExpenseModal">
                    {{ __('expenses.cancel.submit') }}
                </button>
            @endcan
        @endif
    </x-page-header>
@endsection

@section('content')
    @if ($expense->isCancelled())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-slash-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">{{ __('finance.statuses.cancelled') }}</div>
                <div class="small">
                    {{ $expense->cancellation_reason }}
                    &mdash; {{ $expense->canceller?->name }},
                    {{ $expense->cancelled_at?->translatedFormat('d-m-Y H:i') }}
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase mb-1">
                        {{ __('expenses.fields.amount') }}
                    </div>
                    <div class="fs-3 fw-semibold mb-3">
                        <x-money :amount="$expense->amount" :muted="$expense->isCancelled()" />
                    </div>

                    <dl class="row row-cols-1 mb-0 small">
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('expenses.fields.date') }}</dt>
                            <dd class="mb-0">{{ $expense->expense_date->translatedFormat('d-m-Y') }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('expenses.fields.category') }}</dt>
                            <dd class="mb-0">{{ $expense->category?->name }}</dd>
                        </div>
                        @if ($expense->payee_name)
                            <div class="col d-flex justify-content-between border-bottom py-1">
                                <dt class="fw-normal text-body-secondary">{{ __('expenses.fields.payee_name') }}</dt>
                                <dd class="mb-0">{{ $expense->payee_name }}</dd>
                            </div>
                        @endif
                        <div class="col d-flex justify-content-between py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.columns.user') }}</dt>
                            <dd class="mb-0">{{ $expense->creator?->name ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if ($expense->notes)
                        <p class="small text-body-secondary mt-3 mb-0">{{ $expense->notes }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-0">{{ __('finance.funding.title') }}</h3>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('finance.funding.columns.source_type') }}</th>
                                <th scope="col">{{ __('finance.funding.columns.source') }}</th>
                                <th scope="col" class="d-none d-md-table-cell">
                                    {{ __('finance.funding.columns.method') }}
                                </th>
                                <th scope="col" class="text-end">{{ __('finance.funding.columns.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($expense->fundingAllocations as $allocation)
                                <tr>
                                    <td class="small">
                                        {{ __('finance.funding.source_types.'.$allocation->source_type) }}
                                    </td>
                                    <td class="small fw-medium">{{ $allocation->source?->name ?? '—' }}</td>
                                    <td class="d-none d-md-table-cell small text-body-secondary">
                                        {{ $allocation->paymentMethod?->name ?? '—' }}
                                    </td>
                                    <td class="text-end"><x-money :amount="$allocation->amount" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold small">
                                    {{ __('finance.funding.allocated') }}
                                </td>
                                <td class="text-end fw-semibold">
                                    <x-money :amount="$expense->allocatedAmount()" />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="card-body border-top">
                    <p class="small text-body-secondary mb-0">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('finance.funding.help.partner_no_debit') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if (! $expense->isCancelled())
        @can('expense.cancel')
            <div class="modal fade" id="cancelExpenseModal" tabindex="-1"
                 aria-labelledby="cancelExpenseLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('finance.expenses.cancel', $expense) }}" class="modal-content">
                        @csrf
                        @method('PUT')

                        <div class="modal-header">
                            <h2 class="modal-title h6" id="cancelExpenseLabel">{{ __('expenses.cancel.title') }}</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="{{ __('app.actions.close') }}"></button>
                        </div>

                        <div class="modal-body">
                            <p class="small text-body-secondary">{{ __('expenses.cancel.help') }}</p>

                            <label for="cancellation_reason" class="form-label">
                                {{ __('finance.fields.cancellation_reason') }} *
                            </label>
                            <textarea id="cancellation_reason" name="cancellation_reason" rows="3"
                                      class="form-control @error('cancellation_reason') is-invalid @enderror"
                                      required>{{ old('cancellation_reason') }}</textarea>
                            @error('cancellation_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                                {{ __('app.actions.close') }}
                            </button>
                            <button type="submit" class="btn btn-sm btn-danger">
                                {{ __('expenses.cancel.submit') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    @endif
@endsection
