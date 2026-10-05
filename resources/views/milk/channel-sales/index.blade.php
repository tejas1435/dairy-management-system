@extends('layouts.app')

@php
    /*
     * One template for all three Phase 5 sale workflows. They differ in which buyers
     * they list, how the rate is decided and whether quality readings exist — never
     * in the shape of the page — so a conditional on the source is the whole
     * difference rather than three near-identical files.
     */
    use App\Enums\SaleSource;

    $prefix = match ($source) {
        SaleSource::MandaliDelivery => 'buyers.mandali',
        SaleSource::VendorSale => 'buyers.vendor',
        default => 'buyers.other',
    };

    $titleKey = match ($source) {
        SaleSource::MandaliDelivery => $prefix.'.deliveries',
        SaleSource::VendorSale => $prefix.'.sales',
        default => $prefix.'.title',
    };

    $subtitleKey = match ($source) {
        SaleSource::MandaliDelivery => $prefix.'.deliveries_subtitle',
        SaleSource::VendorSale => $prefix.'.sales_subtitle',
        default => $prefix.'.subtitle',
    };

    $createKey = $source === SaleSource::MandaliDelivery
        ? $prefix.'.record_delivery'
        : $prefix.'.record_sale';

    $emptyKey = match ($source) {
        SaleSource::MandaliDelivery => $prefix.'.no_deliveries',
        SaleSource::VendorSale => $prefix.'.no_sales',
        default => $prefix.'.empty',
    };
@endphp

@section('title', __($titleKey))

@section('header')
    <x-page-header :title="__($titleKey)" :subtitle="__($subtitleKey)">
        @can('milk.sale.create')
            @if ($buyers->isNotEmpty())
                <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __($createKey) }}
                </a>
            @endif
        @endcan
    </x-page-header>
@endsection

@section('content')
    <x-alerts />

    @if ($buyers->isEmpty())
        <div class="card shadow-sm">
            <x-empty-state icon="shop"
                           :title="__($source === SaleSource::GenericSale ? 'buyers.other.empty_buyers' : $prefix.'.empty')"
                           :description="__($source === SaleSource::GenericSale ? 'buyers.other.empty_buyers_help' : $prefix.'.empty_help')" />
        </div>
    @else
        <div class="card shadow-sm">
            <div class="card-body border-bottom">
                <form method="GET" action="{{ route($routePrefix.'.index') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label for="buyer" class="form-label small mb-1">{{ __('buyers.sale.fields.buyer') }}</label>
                        <select class="form-select form-select-sm" id="buyer" name="buyer">
                            <option value="">{{ __('app.status.all') }}</option>
                            @foreach ($buyers as $buyer)
                                <option value="{{ $buyer->id }}" @selected((int) ($filters['buyer'] ?? 0) === $buyer->id)>
                                    {{ $buyer->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="from" class="form-label small mb-1">{{ __('buyers.ledger.from') }}</label>
                        <input type="date" class="form-control form-control-sm" id="from" name="from"
                               value="{{ $filters['from'] ?? '' }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="to" class="form-label small mb-1">{{ __('buyers.ledger.to') }}</label>
                        <input type="date" class="form-control form-control-sm" id="to" name="to"
                               value="{{ $filters['to'] ?? '' }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="milk_type" class="form-label small mb-1">{{ __('buyers.sale.fields.milk_type') }}</label>
                        <select class="form-select form-select-sm" id="milk_type" name="milk_type">
                            <option value="">{{ __('app.status.all') }}</option>
                            @foreach (App\Enums\MilkType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(($filters['milk_type'] ?? '') === $type->value)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="status" class="form-label small mb-1">{{ __('app.labels.status') }}</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <option value="">{{ __('app.status.all') }}</option>
                            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('app.status.active') }}</option>
                            <option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>{{ __('buyers.settlement_statuses.cancelled') }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-1">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">{{ __('app.actions.filter') }}</button>
                    </div>
                </form>
            </div>

            @if ($sales->isEmpty())
                <x-empty-state icon="inbox" :title="__($emptyKey)" />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">{{ __($titleKey) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('buyers.sale.fields.date') }}</th>
                                <th scope="col">{{ __('buyers.sale.fields.buyer') }}</th>
                                <th scope="col">{{ __('buyers.sale.fields.shift') }}</th>
                                <th scope="col">{{ __('buyers.sale.fields.milk_type') }}</th>
                                <th scope="col" class="text-end">{{ __('buyers.sale.fields.quantity') }}</th>
                                @if ($source->recordsMilkQuality())
                                    <th scope="col" class="text-end">{{ __('buyers.sale.fields.fat') }}</th>
                                    <th scope="col" class="text-end">{{ __('buyers.sale.fields.snf') }}</th>
                                @endif
                                <th scope="col" class="text-end">{{ __('buyers.sale.fields.rate') }}</th>
                                <th scope="col" class="text-end">{{ __('buyers.sale.fields.amount') }}</th>
                                <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sales as $sale)
                                <tr @class(['text-body-secondary' => $sale->isCancelled()])>
                                    <td class="text-nowrap">{{ $sale->sale_date->translatedFormat('d-m-Y') }}</td>
                                    <td>
                                        {{ $sale->buyer?->name }}
                                        @if ($sale->isCancelled())
                                            <span class="badge rounded-pill text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                                {{ __('buyers.settlement_statuses.cancelled') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $sale->shift->label() }}</td>
                                    <td>{{ $sale->milk_type->label() }}</td>
                                    <td class="text-end"><x-litres :quantity="$sale->quantity" :unit="false" /></td>
                                    @if ($source->recordsMilkQuality())
                                        <td class="text-end">{{ $sale->fat_percentage !== null ? number_format((float) $sale->fat_percentage, 2) : '—' }}</td>
                                        <td class="text-end">{{ $sale->snf_percentage !== null ? number_format((float) $sale->snf_percentage, 2) : '—' }}</td>
                                    @endif
                                    <td class="text-end">
                                        <x-money :amount="$sale->unit_rate" />
                                        @if ($sale->hasRateOverride())
                                            <div class="small text-warning-emphasis">
                                                {{ __('buyers.sale.overridden') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-end"><x-money :amount="$sale->amount" /></td>
                                    <td class="text-end text-nowrap">
                                        @if ($sale->hasSlip() && $source->recordsMilkQuality())
                                            <a href="{{ route($routePrefix.'.slip', $sale) }}"
                                               class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-paperclip" aria-hidden="true"></i>
                                                <span class="visually-hidden">{{ __('buyers.actions.download_slip') }}</span>
                                            </a>
                                        @endif
                                        @unless ($sale->isCancelled())
                                            @can('milk.sale.update')
                                                <a href="{{ route($routePrefix.'.edit', $sale) }}"
                                                   class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>
                                            @endcan
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-body border-top">
                    {{ $sales->links() }}
                </div>
            @endif
        </div>
    @endif
@endsection
