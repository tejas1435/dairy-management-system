@extends('layouts.app')

@section('title', __('expenses.edit_title'))

@section('header')
    <x-page-header :title="__('expenses.edit_title')" :subtitle="$expense->description"
                   :back="route('finance.expenses.show', $expense)" />
@endsection

@section('content')
    {{--
        Only descriptive fields are editable. Amount, date and the funding split
        decided ledger entries that have already been posted, so they are shown
        read-only here and the form request does not accept them at all. A
        correction means cancel and re-enter, which leaves both in the audit
        trail.
    --}}
    <div class="alert alert-light border d-flex align-items-start gap-2 small" role="note">
        <i class="bi bi-lock flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ __('expenses.help.locked_fields') }}</div>
    </div>

    <form method="POST" action="{{ route('finance.expenses.update', $expense) }}" novalidate>
        @csrf
        @method('PUT')

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">{{ __('expenses.fields.date') }}</label>
                        <input type="text" class="form-control" disabled
                               value="{{ $expense->expense_date->translatedFormat('d-m-Y') }}">
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label">{{ __('expenses.fields.amount') }}</label>
                        <input type="text" class="form-control font-monospace text-end" disabled
                               value="{{ number_format((float) $expense->amount, 2) }}">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="expense_category_id" class="form-label">
                            {{ __('expenses.fields.category') }} *
                        </label>
                        <select id="expense_category_id" name="expense_category_id"
                                class="form-select @error('expense_category_id') is-invalid @enderror" required>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}"
                                    @selected((int) old('expense_category_id', $expense->expense_category_id) === $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('expense_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-8">
                        <label for="description" class="form-label">{{ __('expenses.fields.description') }} *</label>
                        <input type="text" id="description" name="description"
                               value="{{ old('description', $expense->description) }}"
                               class="form-control @error('description') is-invalid @enderror" required autofocus>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="payee_name" class="form-label">{{ __('expenses.fields.payee_name') }}</label>
                        <input type="text" id="payee_name" name="payee_name"
                               value="{{ old('payee_name', $expense->payee_name) }}"
                               class="form-control @error('payee_name') is-invalid @enderror">
                        @error('payee_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">{{ __('expenses.fields.notes') }}</label>
                        <textarea id="notes" name="notes" rows="2"
                                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $expense->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body border-bottom">
                <h3 class="h6 mb-0">{{ __('finance.funding.title') }}</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('finance.funding.columns.source_type') }}</th>
                            <th scope="col">{{ __('finance.funding.columns.source') }}</th>
                            <th scope="col" class="text-end">{{ __('finance.funding.columns.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expense->fundingAllocations as $allocation)
                            <tr>
                                <td class="small">{{ __('finance.funding.source_types.'.$allocation->source_type) }}</td>
                                <td class="small fw-medium">{{ $allocation->source?->name ?? '—' }}</td>
                                <td class="text-end"><x-money :amount="$allocation->amount" muted /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save_changes') }}</button>
            <a href="{{ route('finance.expenses.show', $expense) }}" class="btn btn-outline-secondary btn-sm">
                {{ __('app.actions.cancel') }}
            </a>
        </div>
    </form>
@endsection
