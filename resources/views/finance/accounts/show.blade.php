@extends('layouts.app')

@section('title', $account->name)

@section('header')
    <x-page-header :title="$account->name" :subtitle="$account->type->label()"
                   :back="route('finance.accounts.index')">
        <a href="{{ route('finance.accounts.edit', $account) }}" class="btn btn-sm btn-outline-secondary">
            {{ __('app.actions.edit') }}
        </a>
    </x-page-header>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    {{-- Labelled as derived, and visually separated from the
                         opening balance, so the two are never confused. --}}
                    <div class="text-body-secondary small text-uppercase mb-1">
                        {{ __('finance.accounts.columns.balance') }}
                    </div>
                    <div class="fs-3 fw-semibold mb-3"><x-money :amount="$balance" /></div>

                    <dl class="row row-cols-1 mb-0 small">
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">
                                {{ __('finance.accounts.columns.opening_balance') }}
                            </dt>
                            <dd class="mb-0"><x-money :amount="$account->opening_balance" muted /></dd>
                        </div>
                        <div class="col d-flex justify-content-between py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('app.labels.status') }}</dt>
                            <dd class="mb-0"><x-status-badge :active="$account->is_active" /></dd>
                        </div>
                    </dl>

                    <p class="form-text mt-3 mb-0">{{ __('finance.accounts.help.derived_balance') }}</p>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0">{{ __('finance.accounts.recent_entries') }}</h3>
                    @can('finance.cashbook.view')
                        <a href="{{ route('finance.cashbook', ['account' => $account->id]) }}"
                           class="btn btn-sm btn-outline-secondary">{{ __('finance.cashbook.title') }}</a>
                    @endcan
                </div>

                @if ($recentEntries->isEmpty())
                    <div class="card-body">
                        <x-empty-state icon="journal" :description="__('finance.cashbook.empty')" />
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('finance.cashbook.columns.date') }}</th>
                                    <th scope="col">{{ __('finance.cashbook.columns.description') }}</th>
                                    <th scope="col" class="text-end">{{ __('finance.cashbook.columns.debit') }}</th>
                                    <th scope="col" class="text-end">{{ __('finance.cashbook.columns.credit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentEntries as $entry)
                                    <tr>
                                        <td class="small text-nowrap">
                                            {{ $entry->entry_date->translatedFormat('d-m-Y') }}
                                        </td>
                                        <td class="small">
                                            {{ $entry->description }}
                                            @if ($entry->isReversal())
                                                <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle ms-1">
                                                    {{ __('audit.actions.reversed') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($entry->direction->value === 'debit')
                                                <x-money :amount="$entry->amount" />
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($entry->direction->value === 'credit')
                                                <x-money :amount="$entry->amount" />
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <p class="small text-body-secondary mt-3 mb-0">
        <i class="bi bi-lock me-1" aria-hidden="true"></i>{{ __('finance.ledger.immutable_note') }}
    </p>
@endsection
