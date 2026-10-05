@extends('layouts.app')

@section('title', __('finance.cashbook.title'))

@section('header')
    <x-page-header :title="__('finance.cashbook.title')" :subtitle="__('finance.cashbook.subtitle')" />
@endsection

@section('content')
    @if ($accounts->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body">
                <x-empty-state icon="wallet2" :description="__('finance.cashbook.no_accounts')" />
            </div>
        </div>
    @else
        <div class="card shadow-sm">
            <div class="card-body border-bottom">
                <form method="GET" action="{{ route('finance.cashbook') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label for="account" class="form-label small mb-1">
                            {{ __('finance.accounts.columns.name') }}
                        </label>
                        <select id="account" name="account" class="form-select form-select-sm">
                            @foreach ($accounts as $option)
                                <option value="{{ $option->id }}" @selected($account && $account->id === $option->id)>
                                    {{ $option->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="from" class="form-label small mb-1">{{ __('audit.filters.from') }}</label>
                        <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="to" class="form-label small mb-1">{{ __('audit.filters.to') }}</label>
                        <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-secondary flex-grow-1">
                            {{ __('app.actions.filter') }}
                        </button>
                        <a href="{{ route('finance.cashbook') }}" class="btn btn-sm btn-outline-secondary">
                            {{ __('app.actions.clear') }}
                        </a>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-sticky-head align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('finance.cashbook.columns.date') }}</th>
                            <th scope="col">{{ __('finance.cashbook.columns.description') }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">
                                {{ __('finance.cashbook.columns.reference') }}
                            </th>
                            <th scope="col" class="text-end">{{ __('finance.cashbook.columns.debit') }}</th>
                            <th scope="col" class="text-end">{{ __('finance.cashbook.columns.credit') }}</th>
                            <th scope="col" class="text-end">{{ __('finance.cashbook.columns.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- The starting figure is part of the arithmetic, so it
                             is a row rather than a caption: the first running
                             balance visibly follows from it.

                             On page two and beyond it is the balance brought
                             forward from the earlier pages, not the period's
                             opening, or every balance below would be wrong. --}}
                        @php $onLaterPage = $paginator && $paginator->currentPage() > 1; @endphp
                        <tr class="table-light">
                            <td colspan="5" class="fw-medium small">
                                {{ $onLaterPage
                                    ? __('finance.cashbook.brought_forward')
                                    : __('finance.cashbook.opening_balance') }}
                            </td>
                            <td class="text-end fw-medium"><x-money :amount="$broughtForward" /></td>
                        </tr>

                        @forelse ($rows as $row)
                            <tr>
                                <td class="small text-nowrap">
                                    {{ $row->entry->entry_date->translatedFormat('d-m-Y') }}
                                </td>
                                <td class="small">
                                    {{ $row->entry->description }}
                                    @if ($row->entry->isReversal())
                                        <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle ms-1">
                                            {{ __('finance.ledger.reversal_of', ['id' => $row->entry->reverses_entry_id]) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="d-none d-lg-table-cell small text-body-secondary font-monospace">
                                    {{ $row->entry->reference_type ? $row->entry->reference_type.' #'.$row->entry->reference_id : '—' }}
                                </td>
                                <td class="text-end">
                                    @if ($row->entry->direction->value === 'debit')
                                        <x-money :amount="$row->entry->amount" />
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($row->entry->direction->value === 'credit')
                                        <x-money :amount="$row->entry->amount" />
                                    @endif
                                </td>
                                <td class="text-end font-monospace"><x-money :amount="$row->running_balance" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state icon="journal-text" :description="__('finance.cashbook.empty')" />
                                </td>
                            </tr>
                        @endforelse

                        <tr class="table-light">
                            <td colspan="5" class="fw-semibold small">
                                {{ $paginator && $paginator->hasMorePages()
                                    ? __('finance.cashbook.carried_forward')
                                    : __('finance.cashbook.closing_balance') }}
                            </td>
                            <td class="text-end fw-semibold"><x-money :amount="$closing" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if ($paginator && $paginator->hasPages())
                <div class="card-body border-top">{{ $paginator->links() }}</div>
            @endif
        </div>

        <div class="small text-body-secondary mt-3">
            <p class="mb-1">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('finance.accounts.help.derived_balance') }}
            </p>
            {{-- Stated rather than shown as disabled buttons: Phase 9 owns exports. --}}
            <p class="mb-0">
                <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>{{ __('finance.cashbook.exports_note') }}
            </p>
        </div>
    @endif
@endsection
