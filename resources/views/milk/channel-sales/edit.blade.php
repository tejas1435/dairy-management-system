@extends('layouts.app')

@section('title', __('buyers.sale.edit'))

@section('header')
    <x-page-header :title="__('buyers.sale.edit')"
                   :subtitle="$sale->buyer?->name.' · '.$sale->sale_date->translatedFormat('d-m-Y').' · '.$sale->shift->label()"
                   :back="route($routePrefix.'.index')" />
@endsection

@section('content')
    <x-validation-errors />

    {{--
        The buyer, date, shift and milk type are not editable. Changing any of them
        would move the sale to a different reconciliation unit or a different account,
        which makes it a different sale — so the correct action is to withdraw this one
        and record the right one, leaving both in the history.
    --}}
    <div class="alert alert-secondary py-2 small" role="note">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        {{ __('buyers.sale.help.amount_calculated') }}
        <span class="ms-2">
            {{ __('buyers.sale.help.remaining_milk') }}
            <x-litres :quantity="$remaining" class="ms-1" />
        </span>
    </div>

    <form method="POST" action="{{ route($routePrefix.'.update', $sale) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label for="quantity" class="form-label">{{ __('buyers.sale.fields.quantity') }} *</label>
                        <input type="number" step="0.001" min="0.001" inputmode="decimal"
                               class="form-control @error('quantity') is-invalid @enderror"
                               id="quantity" name="quantity"
                               value="{{ old('quantity', number_format((float) $sale->quantity, 3, '.', '')) }}" required>
                        @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if ($sale->source->recordsMilkQuality())
                        <div class="col-6 col-md-2">
                            <label for="fat_percentage" class="form-label">{{ __('buyers.sale.fields.fat') }}</label>
                            <input type="number" step="0.01" min="0" max="15" inputmode="decimal"
                                   class="form-control @error('fat_percentage') is-invalid @enderror"
                                   id="fat_percentage" name="fat_percentage"
                                   value="{{ old('fat_percentage', $sale->fat_percentage) }}">
                            @error('fat_percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="snf_percentage" class="form-label">{{ __('buyers.sale.fields.snf') }}</label>
                            <input type="number" step="0.01" min="0" max="15" inputmode="decimal"
                                   class="form-control @error('snf_percentage') is-invalid @enderror"
                                   id="snf_percentage" name="snf_percentage"
                                   value="{{ old('snf_percentage', $sale->snf_percentage) }}">
                            @error('snf_percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <div class="alert alert-secondary py-2 small mb-0" role="note">
                                {{ __('buyers.sale.help.fat_snf') }}
                            </div>
                        </div>
                    @endif

                    {{--
                        The rate is left empty on purpose. An empty field keeps the
                        stored snapshot (D41); typing a different figure is a rate
                        change, which needs the override permission and a reason.
                    --}}
                    <div class="col-6 col-md-3">
                        <label for="unit_rate" class="form-label">{{ __('buyers.sale.fields.rate') }}</label>
                        <input type="number" step="0.01" min="0.01" inputmode="decimal"
                               class="form-control @error('unit_rate') is-invalid @enderror"
                               id="unit_rate" name="unit_rate" value="{{ old('unit_rate') }}"
                               placeholder="{{ number_format((float) $sale->unit_rate, 2, '.', '') }}">
                        @error('unit_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            {{ __('buyers.sale.fields.rate') }}:
                            <x-money :amount="$sale->unit_rate" />
                            @if ($sale->hasRateOverride())
                                <span class="text-warning-emphasis">
                                    · {{ __('buyers.sale.overridden_from', ['rate' => number_format((float) $sale->resolved_rate, 2)]) }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="rate_override_reason" class="form-label">{{ __('buyers.sale.fields.override_reason') }}</label>
                        <input type="text" maxlength="500"
                               class="form-control @error('rate_override_reason') is-invalid @enderror"
                               id="rate_override_reason" name="rate_override_reason"
                               value="{{ old('rate_override_reason') }}">
                        @error('rate_override_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @cannot('milk.sale.override_rate')
                            <div class="form-text">{{ __('buyers.errors.rate_change_not_permitted') }}</div>
                        @endcannot
                    </div>

                    @if ($sale->source->recordsMilkQuality())
                        <div class="col-12 col-md-4">
                            <label for="slip" class="form-label">{{ __('buyers.sale.fields.slip') }}</label>
                            <input type="file" class="form-control @error('slip') is-invalid @enderror"
                                   id="slip" name="slip" accept=".pdf,.jpg,.jpeg,.png,.webp">
                            @error('slip')<div class="invalid-feedback">{{ $message }}</div>@enderror

                            @if ($sale->hasSlip())
                                <div class="form-text">
                                    <a href="{{ route($routePrefix.'.slip', $sale) }}">
                                        <i class="bi bi-paperclip me-1" aria-hidden="true"></i>{{ $sale->slip_name }}
                                    </a>
                                </div>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="remove_slip" name="remove_slip">
                                    <label class="form-check-label small" for="remove_slip">
                                        {{ __('buyers.actions.remove_slip') }}
                                    </label>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="col-12">
                        <label for="notes" class="form-label">{{ __('buyers.sale.fields.notes') }}</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes', $sale->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="card-body border-top text-end">
                <a href="{{ route($routePrefix.'.index') }}" class="btn btn-outline-secondary">{{ __('app.actions.cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('app.actions.save_changes') }}</button>
            </div>
        </div>
    </form>

    @can('milk.sale.cancel')
        <div class="card shadow-sm border-danger-subtle">
            <div class="card-body">
                <h2 class="h6">{{ __('buyers.sale.cancel') }}</h2>
                <p class="small text-body-secondary">{{ __('buyers.sale.cancel_help') }}</p>

                <form method="POST" action="{{ route($routePrefix.'.cancel', $sale) }}" class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    <div class="col-12 col-md-8">
                        <label for="cancellation_reason" class="form-label small mb-1">
                            {{ __('milk.fields.cancellation_reason') }} *
                        </label>
                        <input type="text" class="form-control @error('cancellation_reason') is-invalid @enderror"
                               id="cancellation_reason" name="cancellation_reason"
                               minlength="5" maxlength="1000" required>
                        @error('cancellation_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="submit" class="btn btn-danger">{{ __('buyers.sale.cancel') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
