@extends('layouts.app')

@php
    use App\Enums\SaleSource;

    $prefix = match ($source) {
        SaleSource::MandaliDelivery => 'buyers.mandali',
        SaleSource::VendorSale => 'buyers.vendor',
        default => 'buyers.other',
    };

    $titleKey = $source === SaleSource::MandaliDelivery
        ? $prefix.'.record_delivery'
        : $prefix.'.record_sale';

    $buyerLabel = match ($source) {
        SaleSource::MandaliDelivery => 'buyers.sale.fields.mandali',
        SaleSource::VendorSale => 'buyers.sale.fields.vendor',
        default => 'buyers.sale.fields.buyer',
    };

    // The rate help text is the one real difference between the three workflows.
    $rateHelp = match ($source) {
        SaleSource::MandaliDelivery => 'buyers.sale.help.manual_rate',
        SaleSource::VendorSale => 'buyers.sale.help.vendor_rate',
        default => 'buyers.sale.help.generic_rate',
    };
@endphp

@section('title', __($titleKey))

@section('header')
    <x-page-header :title="__($titleKey)" :back="route($routePrefix.'.index')" />
@endsection

@section('content')
    <x-validation-errors />

    {{--
        The shift's remaining milk, so the operator sees the ceiling before hitting
        it. Information only: the server refuses an over-allocating save on its own
        terms, inside the write transaction.
    --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
            <span class="small text-body-secondary">
                <i class="bi bi-droplet me-1" aria-hidden="true"></i>{{ __('buyers.sale.help.remaining_milk') }}
            </span>
            @foreach ($shifts as $shift)
                @foreach ($milkTypes as $milkType)
                    @php $cell = $availability[$shift->value][$milkType->value]; @endphp
                    <span class="small">
                        <span class="text-body-secondary">{{ $shift->label() }} {{ $milkType->label() }}</span>
                        @if ($cell['production_entered'])
                            <x-litres :quantity="$cell['remaining']" class="ms-1" />
                        @else
                            <span class="badge text-bg-warning-subtle text-warning-emphasis ms-1">
                                {{ __('milk.not_entered_short') }}
                            </span>
                        @endif
                    </span>
                @endforeach
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label for="buyer_id" class="form-label">{{ __($buyerLabel) }} *</label>
                        <select class="form-select @error('buyer_id') is-invalid @enderror"
                                id="buyer_id" name="buyer_id" required>
                            <option value="">{{ __('app.status.none') }}</option>
                            @foreach ($buyers as $buyer)
                                <option value="{{ $buyer->id }}"
                                        @selected((int) old('buyer_id', $selectedBuyerId) === $buyer->id)>
                                    {{ $buyer->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('buyer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="sale_date" class="form-label">{{ __('buyers.sale.fields.date') }} *</label>
                        <input type="date" class="form-control @error('sale_date') is-invalid @enderror"
                               id="sale_date" name="sale_date"
                               value="{{ old('sale_date', $date->toDateString()) }}" required>
                        @error('sale_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="shift" class="form-label">{{ __('buyers.sale.fields.shift') }} *</label>
                        <select class="form-select @error('shift') is-invalid @enderror" id="shift" name="shift" required>
                            @foreach ($shifts as $shift)
                                <option value="{{ $shift->value }}" @selected(old('shift') === $shift->value)>
                                    {{ $shift->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="milk_type" class="form-label">{{ __('buyers.sale.fields.milk_type') }} *</label>
                        <select class="form-select @error('milk_type') is-invalid @enderror"
                                id="milk_type" name="milk_type" required>
                            @foreach ($milkTypes as $milkType)
                                <option value="{{ $milkType->value }}" @selected(old('milk_type') === $milkType->value)>
                                    {{ $milkType->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('milk_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="quantity" class="form-label">{{ __('buyers.sale.fields.quantity') }} *</label>
                        <input type="number" step="0.001" min="0.001" inputmode="decimal"
                               class="form-control @error('quantity') is-invalid @enderror"
                               id="quantity" name="quantity" value="{{ old('quantity') }}" required>
                        @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if ($source->recordsMilkQuality())
                        <div class="col-6 col-md-2">
                            <label for="fat_percentage" class="form-label">{{ __('buyers.sale.fields.fat') }} *</label>
                            <input type="number" step="0.01" min="0" max="15" inputmode="decimal"
                                   class="form-control @error('fat_percentage') is-invalid @enderror"
                                   id="fat_percentage" name="fat_percentage" value="{{ old('fat_percentage') }}" required>
                            @error('fat_percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="snf_percentage" class="form-label">
                                {{ __('buyers.sale.fields.snf') }}
                                <span class="text-body-secondary small">({{ __('app.labels.optional') }})</span>
                            </label>
                            <input type="number" step="0.01" min="0" max="15" inputmode="decimal"
                                   class="form-control @error('snf_percentage') is-invalid @enderror"
                                   id="snf_percentage" name="snf_percentage" value="{{ old('snf_percentage') }}">
                            @error('snf_percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <div class="alert alert-secondary py-2 small mb-0" role="note">
                                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('buyers.sale.help.fat_snf') }}
                            </div>
                        </div>
                    @endif

                    <div class="col-6 col-md-3">
                        <label for="unit_rate" class="form-label">
                            {{ __('buyers.sale.fields.manual_rate') }}
                            @if ($source->usesManualRate()) * @endif
                        </label>
                        <input type="number" step="0.01" min="0.01" inputmode="decimal"
                               class="form-control @error('unit_rate') is-invalid @enderror"
                               id="unit_rate" name="unit_rate" value="{{ old('unit_rate') }}"
                               @required($source->usesManualRate())>
                        @error('unit_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __($rateHelp) }}</div>
                    </div>

                    @unless ($source->usesManualRate())
                        <div class="col-12 col-md-5">
                            <label for="rate_override_reason" class="form-label">
                                {{ __('buyers.sale.fields.override_reason') }}
                            </label>
                            <input type="text" maxlength="500"
                                   class="form-control @error('rate_override_reason') is-invalid @enderror"
                                   id="rate_override_reason" name="rate_override_reason"
                                   value="{{ old('rate_override_reason') }}">
                            @error('rate_override_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @cannot('milk.sale.override_rate')
                                <div class="form-text">{{ __('buyers.errors.rate_override_not_permitted') }}</div>
                            @endcannot
                        </div>
                    @endunless

                    @if ($source->recordsMilkQuality())
                        <div class="col-12 col-md-4">
                            <label for="slip" class="form-label">
                                {{ __('buyers.sale.fields.slip') }}
                                <span class="text-body-secondary small">({{ __('app.labels.optional') }})</span>
                            </label>
                            <input type="file" class="form-control @error('slip') is-invalid @enderror"
                                   id="slip" name="slip" accept=".pdf,.jpg,.jpeg,.png,.webp">
                            @error('slip')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('buyers.sale.help.slip') }}</div>
                        </div>
                    @endif

                    <div class="col-12">
                        <label for="notes" class="form-label">{{ __('buyers.sale.fields.notes') }}</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                <p class="small text-body-secondary mb-0">{{ __('buyers.sale.help.amount_calculated') }}</p>
                <div>
                    <a href="{{ route($routePrefix.'.index') }}" class="btn btn-outline-secondary">{{ __('app.actions.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('app.actions.save') }}</button>
                </div>
            </div>
        </div>
    </form>
@endsection
