@extends('layouts.app')

@section('title', __('expenses.create_title'))

@section('header')
    <x-page-header :title="__('expenses.create_title')" :back="route('finance.expenses.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('finance.expenses.store') }}" novalidate>
        @csrf

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label for="expense_date" class="form-label">{{ __('expenses.fields.date') }} *</label>
                        <input type="date" id="expense_date" name="expense_date"
                               value="{{ old('expense_date', now()->toDateString()) }}"
                               class="form-control @error('expense_date') is-invalid @enderror" required>
                        @error('expense_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="expense_category_id" class="form-label">{{ __('expenses.fields.category') }} *</label>
                        <select id="expense_category_id" name="expense_category_id"
                                class="form-select @error('expense_category_id') is-invalid @enderror" required>
                            <option value="">{{ __('finance.funding.select_source') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}"
                                    @selected((int) old('expense_category_id') === $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('expense_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="amount" class="form-label">{{ __('expenses.fields.amount') }} *</label>
                        <div class="input-group">
                            <span class="input-group-text">&#8377;</span>
                            {{-- The funding editor watches this field to compute
                                 its remaining figure. --}}
                            <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                   id="amount" name="amount" value="{{ old('amount') }}"
                                   class="form-control font-monospace text-end @error('amount') is-invalid @enderror"
                                   required autofocus>
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="col-12 col-md-2">
                        <label for="payee_name" class="form-label">{{ __('expenses.fields.payee_name') }}</label>
                        <input type="text" id="payee_name" name="payee_name" value="{{ old('payee_name') }}"
                               class="form-control @error('payee_name') is-invalid @enderror">
                        @error('payee_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label">{{ __('expenses.fields.description') }} *</label>
                        <input type="text" id="description" name="description" value="{{ old('description') }}"
                               class="form-control @error('description') is-invalid @enderror" required>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">{{ __('expenses.fields.notes') }}</label>
                        <textarea id="notes" name="notes" rows="2"
                                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <x-funding-editor :partners="$partners" :accounts="$accounts" :payment-methods="$paymentMethods" />

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save') }}</button>
            <a href="{{ route('finance.expenses.index') }}" class="btn btn-outline-secondary btn-sm">
                {{ __('app.actions.cancel') }}
            </a>
        </div>
    </form>
@endsection
