@extends('layouts.app')

@section('title', __('buyers.settlement.create'))

@section('header')
    <x-page-header :title="__('buyers.settlement.create')"
                   :subtitle="$mandali->name"
                   :back="route('mandalis.settlements.index', $mandali)" />
@endsection

@section('content')
    <x-validation-errors />

    {{--
        The live figures for the proposed period, so the operator can see what it
        contains before opening a settlement over it. Shown here because this is a
        draft: a draft has no accounting effect, so live figures are safe. Once
        finalized the settlement shows its own snapshots instead.
    --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2 d-flex flex-wrap gap-3 small">
            <span>
                <span class="text-body-secondary">{{ __('buyers.settlement.deliveries_in_period') }}</span>
                <strong class="ms-1">{{ $preview['sale_count'] }}</strong>
            </span>
            <span>
                <span class="text-body-secondary">{{ __('buyers.settlement.fields.milk_quantity') }}</span>
                <x-litres :quantity="$preview['milk_quantity']" class="ms-1" />
            </span>
            <span>
                <span class="text-body-secondary">{{ __('buyers.settlement.fields.expected_amount') }}</span>
                <x-money :amount="$preview['expected_amount']" class="ms-1" />
            </span>
        </div>
    </div>

    <form method="POST" action="{{ route('mandalis.settlements.store', $mandali) }}">
        @csrf

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label for="period_start" class="form-label">{{ __('buyers.settlement.fields.period_start') }} *</label>
                        <input type="date" class="form-control @error('period_start') is-invalid @enderror"
                               id="period_start" name="period_start"
                               value="{{ old('period_start', $periodStart) }}" required>
                        @error('period_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="period_end" class="form-label">{{ __('buyers.settlement.fields.period_end') }} *</label>
                        <input type="date" class="form-control @error('period_end') is-invalid @enderror"
                               id="period_end" name="period_end"
                               value="{{ old('period_end', $periodEnd) }}" required>
                        @error('period_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('buyers.settlement.period_help') }}</div>
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="statement_amount" class="form-label">
                            {{ __('buyers.settlement.fields.statement_amount') }}
                            <span class="text-body-secondary small">({{ __('app.labels.optional') }})</span>
                        </label>
                        <input type="number" step="0.01" min="0" inputmode="decimal"
                               class="form-control @error('statement_amount') is-invalid @enderror"
                               id="statement_amount" name="statement_amount" value="{{ old('statement_amount') }}">
                        @error('statement_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('buyers.settlement.help.statement_amount') }}</div>
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">{{ __('buyers.settlement.fields.notes') }}</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                <p class="small text-body-secondary mb-0">{{ __('buyers.settlement.draft_help') }}</p>
                <div>
                    <a href="{{ route('mandalis.settlements.index', $mandali) }}"
                       class="btn btn-outline-secondary">{{ __('app.actions.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('app.actions.create') }}</button>
                </div>
            </div>
        </div>
    </form>
@endsection
