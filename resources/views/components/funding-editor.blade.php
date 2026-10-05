@props([
    'partners' => collect(),
    'accounts' => collect(),
    'paymentMethods' => collect(),
    'allocations' => [],
    'amountInput' => '#amount',
])

{{--
    Reusable split-funding editor.

    Phase 2 uses it for expenses. Phase 6 (animal purchase) and Phase 7 (payroll
    payments, loan disbursements) reuse it by passing a different payable, which
    is why nothing here mentions expenses.

    The running totals are a convenience only. AllocateFundingSources revalidates
    the whole split inside the database transaction and rejects anything that
    does not sum exactly, so nothing here can authorise a bad save.
--}}

@php
    $rows = old('allocations', $allocations ?: [['source_type' => 'financial_account']]);
@endphp

<div class="card border" data-funding-editor data-amount-input="{{ $amountInput }}">
    <div class="card-body border-bottom py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h3 class="h6 mb-0">{{ __('finance.funding.title') }}</h3>
            <div class="small text-body-secondary">{{ __('finance.funding.subtitle') }}</div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" data-funding-add>
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('finance.funding.add_source') }}
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col" style="width: 9rem">{{ __('finance.funding.columns.source_type') }}</th>
                    <th scope="col">{{ __('finance.funding.columns.source') }}</th>
                    <th scope="col" style="width: 10rem">{{ __('finance.funding.columns.amount') }}</th>
                    <th scope="col" class="d-none d-lg-table-cell" style="width: 9rem">
                        {{ __('finance.funding.columns.method') }}
                    </th>
                    <th scope="col" class="d-none d-xl-table-cell">{{ __('finance.funding.columns.reference') }}</th>
                    <th scope="col" style="width: 3rem"><span class="visually-hidden">{{ __('app.labels.actions') }}</span></th>
                </tr>
            </thead>
            <tbody data-funding-rows>
                @foreach ($rows as $index => $row)
                    <tr data-funding-row>
                        <td>
                            <select name="allocations[{{ $index }}][source_type]"
                                    class="form-select form-select-sm" data-funding-type
                                    aria-label="{{ __('finance.funding.columns.source_type') }}">
                                <option value="financial_account" @selected(($row['source_type'] ?? '') === 'financial_account')>
                                    {{ __('finance.funding.source_types.financial_account') }}
                                </option>
                                <option value="partner" @selected(($row['source_type'] ?? '') === 'partner')>
                                    {{ __('finance.funding.source_types.partner') }}
                                </option>
                            </select>
                        </td>
                        <td>
                            {{-- Two selectors, one shown at a time, so the posted
                                 id can only ever be of the chosen type. --}}
                            <select name="allocations[{{ $index }}][source_id]"
                                    class="form-select form-select-sm @error("allocations.{$index}.source_id") is-invalid @enderror"
                                    data-funding-source="financial_account"
                                    aria-label="{{ __('finance.funding.columns.source') }}">
                                <option value="">{{ __('finance.funding.select_source') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}"
                                        @selected(($row['source_type'] ?? '') === 'financial_account' && (int) ($row['source_id'] ?? 0) === $account->id)>
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>

                            <select name="allocations[{{ $index }}][source_id]"
                                    class="form-select form-select-sm d-none"
                                    data-funding-source="partner" disabled
                                    aria-label="{{ __('finance.funding.columns.source') }}">
                                <option value="">{{ __('finance.funding.select_source') }}</option>
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}"
                                        @selected(($row['source_type'] ?? '') === 'partner' && (int) ($row['source_id'] ?? 0) === $partner->id)>
                                        {{ $partner->name }}
                                    </option>
                                @endforeach
                            </select>

                            @error("allocations.{$index}.source_id")
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </td>
                        <td>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">&#8377;</span>
                                <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                       name="allocations[{{ $index }}][amount]"
                                       value="{{ $row['amount'] ?? '' }}"
                                       class="form-control font-monospace text-end @error("allocations.{$index}.amount") is-invalid @enderror"
                                       data-funding-amount
                                       aria-label="{{ __('finance.funding.columns.amount') }}">
                            </div>
                            @error("allocations.{$index}.amount")
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </td>
                        <td class="d-none d-lg-table-cell">
                            <select name="allocations[{{ $index }}][payment_method_id]"
                                    class="form-select form-select-sm"
                                    aria-label="{{ __('finance.funding.columns.method') }}">
                                <option value="">&mdash;</option>
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method->id }}"
                                        @selected((int) ($row['payment_method_id'] ?? 0) === $method->id)>
                                        {{ $method->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td class="d-none d-xl-table-cell">
                            <input type="text" name="allocations[{{ $index }}][reference]"
                                   value="{{ $row['reference'] ?? '' }}"
                                   class="form-control form-control-sm"
                                   aria-label="{{ __('finance.funding.columns.reference') }}">
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger" data-funding-remove
                                    aria-label="{{ __('finance.funding.remove_row') }}">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card-body border-top py-2">
        @error('allocations')
            <div class="alert alert-danger py-2 small mb-2">{{ $message }}</div>
        @enderror

        <div class="d-flex flex-wrap justify-content-end gap-4 small">
            <div class="text-end">
                <div class="text-body-secondary">{{ __('finance.funding.allocated') }}</div>
                <div class="fw-semibold font-monospace" data-funding-allocated>&#8377;0.00</div>
            </div>
            <div class="text-end">
                <div class="text-body-secondary">{{ __('finance.funding.remaining') }}</div>
                <div class="fw-semibold font-monospace" data-funding-remaining>&#8377;0.00</div>
            </div>
        </div>
    </div>
</div>

<div class="small text-body-secondary mt-2">
    <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('finance.funding.help.partner_no_debit') }}</p>
    <p class="mb-0"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ __('finance.funding.help.server_checked') }}</p>
</div>

{{-- Template for a new row, cloned by the JS module. --}}
<template data-funding-template>
    <tr data-funding-row>
        <td>
            <select name="allocations[__INDEX__][source_type]" class="form-select form-select-sm" data-funding-type
                    aria-label="{{ __('finance.funding.columns.source_type') }}">
                <option value="financial_account">{{ __('finance.funding.source_types.financial_account') }}</option>
                <option value="partner">{{ __('finance.funding.source_types.partner') }}</option>
            </select>
        </td>
        <td>
            <select name="allocations[__INDEX__][source_id]" class="form-select form-select-sm"
                    data-funding-source="financial_account" aria-label="{{ __('finance.funding.columns.source') }}">
                <option value="">{{ __('finance.funding.select_source') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            <select name="allocations[__INDEX__][source_id]" class="form-select form-select-sm d-none"
                    data-funding-source="partner" disabled aria-label="{{ __('finance.funding.columns.source') }}">
                <option value="">{{ __('finance.funding.select_source') }}</option>
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                @endforeach
            </select>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">&#8377;</span>
                <input type="number" step="0.01" min="0.01" inputmode="decimal"
                       name="allocations[__INDEX__][amount]" class="form-control font-monospace text-end"
                       data-funding-amount aria-label="{{ __('finance.funding.columns.amount') }}">
            </div>
        </td>
        <td class="d-none d-lg-table-cell">
            <select name="allocations[__INDEX__][payment_method_id]" class="form-select form-select-sm"
                    aria-label="{{ __('finance.funding.columns.method') }}">
                <option value="">&mdash;</option>
                @foreach ($paymentMethods as $method)
                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                @endforeach
            </select>
        </td>
        <td class="d-none d-xl-table-cell">
            <input type="text" name="allocations[__INDEX__][reference]" class="form-control form-control-sm"
                   aria-label="{{ __('finance.funding.columns.reference') }}">
        </td>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" data-funding-remove
                    aria-label="{{ __('finance.funding.remove_row') }}">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </td>
    </tr>
</template>
