@extends('layouts.app')

@section('title', $partner->name)

@section('header')
    <x-page-header :title="$partner->name" :subtitle="__('partners.ledger.title')"
                   :back="route('finance.partners.index')">
        @can('partner.update')
            <a href="{{ route('finance.partners.edit', $partner) }}" class="btn btn-sm btn-outline-secondary">
                {{ __('app.actions.edit') }}
            </a>
        @endcan
        @can('partner.contribution.create')
            @if ($partner->is_active)
                <button type="button" class="btn btn-sm btn-primary"
                        data-bs-toggle="modal" data-bs-target="#contributionModal">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('partners.contribution.title') }}
                </button>
            @endif
        @endcan
    </x-page-header>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    @can('partner.finance.view')
                        <div class="text-body-secondary small text-uppercase mb-1">
                            {{ __('partners.ledger.period_total') }}
                        </div>
                        <div class="fs-3 fw-semibold mb-3"><x-money :amount="$total" /></div>
                    @endcan

                    <dl class="row row-cols-1 mb-0 small">
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('partners.fields.mobile') }}</dt>
                            <dd class="mb-0">{{ $partner->mobile ?: '—' }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('partners.fields.email') }}</dt>
                            <dd class="mb-0 text-truncate">{{ $partner->email ?: '—' }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('partners.fields.joining_date') }}</dt>
                            <dd class="mb-0">{{ $partner->joining_date?->translatedFormat('d-m-Y') ?: '—' }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('app.labels.status') }}</dt>
                            <dd class="mb-0"><x-status-badge :active="$partner->is_active" /></dd>
                        </div>
                    </dl>

                    @if ($partner->notes)
                        <p class="small text-body-secondary mt-3 mb-0">{{ $partner->notes }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-1">{{ __('partners.ledger.title') }}</h3>
                    <p class="small text-body-secondary mb-3">{{ __('partners.ledger.subtitle') }}</p>

                    @can('partner.finance.view')
                        <form method="GET" action="{{ route('finance.partners.show', $partner) }}"
                              class="row g-2 align-items-end">
                            <div class="col-6 col-md-4">
                                <label for="from" class="form-label small mb-1">{{ __('audit.filters.from') }}</label>
                                <input type="date" id="from" name="from" value="{{ $period['from'] ?? '' }}"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-4">
                                <label for="to" class="form-label small mb-1">{{ __('audit.filters.to') }}</label>
                                <input type="date" id="to" name="to" value="{{ $period['to'] ?? '' }}"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-12 col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-secondary flex-grow-1">
                                    {{ __('app.actions.filter') }}
                                </button>
                                <a href="{{ route('finance.partners.show', $partner) }}"
                                   class="btn btn-sm btn-outline-secondary">{{ __('app.actions.clear') }}</a>
                            </div>
                        </form>
                    @endcan
                </div>

                @cannot('partner.finance.view')
                    <div class="card-body">
                        <x-empty-state icon="lock" :title="__('app.errors.forbidden')"
                                       :description="__('partners.ledger.subtitle')" />
                    </div>
                @else
                    @if ($entries->isEmpty())
                        <div class="card-body">
                            <x-empty-state icon="journal" :description="__('partners.ledger.empty')" />
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-sticky-head align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('partners.ledger.columns.date') }}</th>
                                        <th scope="col">{{ __('partners.ledger.columns.type') }}</th>
                                        <th scope="col">{{ __('partners.ledger.columns.description') }}</th>
                                        <th scope="col" class="d-none d-lg-table-cell">
                                            {{ __('partners.ledger.columns.reference') }}
                                        </th>
                                        <th scope="col" class="text-end">
                                            {{ __('partners.ledger.columns.amount') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($entries as $entry)
                                        <tr>
                                            <td class="small text-nowrap">
                                                {{ $entry->date->translatedFormat('d-m-Y') }}
                                            </td>
                                            <td class="small">
                                                <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
                                                    {{ $entry->type_label }}
                                                </span>
                                            </td>
                                            <td class="small">
                                                @if ($entry->url)
                                                    <a href="{{ $entry->url }}">{{ $entry->description }}</a>
                                                @else
                                                    {{ $entry->description }}
                                                @endif
                                            </td>
                                            <td class="d-none d-lg-table-cell small text-body-secondary font-monospace">
                                                {{ $entry->reference }}
                                            </td>
                                            <td class="text-end"><x-money :amount="$entry->amount" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <td colspan="4" class="fw-semibold small">
                                            {{ __('partners.ledger.period_total') }}
                                        </td>
                                        <td class="text-end fw-semibold"><x-money :amount="$total" /></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                @endcannot
            </div>
        </div>
    </div>

    @can('partner.contribution.create')
        @if ($partner->is_active)
            <div class="modal fade" id="contributionModal" tabindex="-1"
                 aria-labelledby="contributionLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('finance.partners.contributions.store', $partner) }}"
                          class="modal-content" novalidate>
                        @csrf

                        <div class="modal-header">
                            <h2 class="modal-title h6" id="contributionLabel">
                                {{ __('partners.contribution.title') }}
                            </h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="{{ __('app.actions.close') }}"></button>
                        </div>

                        <div class="modal-body">
                            <p class="small text-body-secondary">{{ __('partners.contribution.help') }}</p>

                            <div class="row g-3">
                                <div class="col-6">
                                    <label for="contribution_date" class="form-label">
                                        {{ __('partners.fields.contribution_date') }} *
                                    </label>
                                    <input type="date" id="contribution_date" name="contribution_date"
                                           value="{{ old('contribution_date', now()->toDateString()) }}"
                                           class="form-control @error('contribution_date') is-invalid @enderror" required>
                                    @error('contribution_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="col-6">
                                    <label for="amount" class="form-label">{{ __('partners.fields.amount') }} *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">&#8377;</span>
                                        <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                               id="amount" name="amount" value="{{ old('amount') }}"
                                               class="form-control font-monospace text-end @error('amount') is-invalid @enderror"
                                               required>
                                        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label for="financial_account_id" class="form-label">
                                        {{ __('partners.fields.account') }} *
                                    </label>
                                    <select id="financial_account_id" name="financial_account_id"
                                            class="form-select @error('financial_account_id') is-invalid @enderror" required>
                                        <option value="">{{ __('finance.funding.select_source') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}"
                                                @selected((int) old('financial_account_id') === $account->id)>
                                                {{ $account->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="col-6">
                                    <label for="payment_method_id" class="form-label">
                                        {{ __('partners.fields.payment_method') }}
                                    </label>
                                    <select id="payment_method_id" name="payment_method_id" class="form-select">
                                        <option value="">&mdash;</option>
                                        @foreach ($paymentMethods as $method)
                                            <option value="{{ $method->id }}"
                                                @selected((int) old('payment_method_id') === $method->id)>
                                                {{ $method->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-6">
                                    <label for="reference" class="form-label">{{ __('partners.fields.reference') }}</label>
                                    <input type="text" id="reference" name="reference" value="{{ old('reference') }}"
                                           class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                                {{ __('app.actions.cancel') }}
                            </button>
                            <button type="submit" class="btn btn-sm btn-primary">
                                {{ __('partners.contribution.submit') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endcan
@endsection
