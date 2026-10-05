@extends('layouts.app')

@section('title', $buyer->name)

@section('header')
    <x-page-header :title="$buyer->name" :subtitle="$buyer->salesChannel?->name"
                   :back="route('buyers.index')">
        @can('update', $buyer)
            <a href="{{ route('buyers.edit', $buyer) }}" class="btn btn-sm btn-outline-secondary">
                {{ __('app.actions.edit') }}
            </a>
        @endcan
    </x-page-header>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <dl class="row row-cols-1 mb-0 small">
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('buyers.fields.channel') }}</dt>
                            <dd class="mb-0">{{ $buyer->salesChannel?->name }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('buyers.fields.mobile') }}</dt>
                            <dd class="mb-0">{{ $buyer->mobile ?: '—' }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('buyers.fields.area') }}</dt>
                            <dd class="mb-0">{{ $buyer->area ?: '—' }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('buyers.fields.payment_cycle') }}</dt>
                            <dd class="mb-0">{{ $buyer->payment_cycle ?: '—' }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('app.labels.status') }}</dt>
                            <dd class="mb-0"><x-status-badge :active="$buyer->is_active" /></dd>
                        </div>
                    </dl>

                    @if ($buyer->address)
                        <p class="small text-body-secondary mt-3 mb-0">{{ $buyer->address }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-1">{{ __('pricing.buyer.title') }}</h3>
                    <p class="small text-body-secondary mb-0">{{ __('pricing.buyer.subtitle') }}</p>
                </div>

                <div class="card-body border-bottom">
                    <div class="row g-3">
                        @foreach ($milkTypes as $milkType)
                            @php $price = $resolved[$milkType->value]; @endphp
                            <div class="col-12 col-sm-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="small text-body-secondary">{{ $milkType->label() }}</div>
                                    @if ($price->found)
                                        <div class="fs-5 fw-semibold font-monospace">
                                            &#8377;{{ number_format((float) $price->rate, 2) }}
                                        </div>
                                        {{-- Says plainly which rule won, so absence of an
                                             override reads as "falls back", not "missing". --}}
                                        <span class="badge {{ $price->isFromBuyerOverride()
                                            ? 'text-primary-emphasis bg-primary-subtle border border-primary-subtle'
                                            : 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle' }}">
                                            {{ $price->isFromBuyerOverride()
                                                ? __('pricing.buyer.using_override')
                                                : __('pricing.buyer.using_default') }}
                                        </span>
                                    @else
                                        <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                            {{ __('pricing.no_current') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @foreach ($milkTypes as $milkType)
                    @php $rules = $history[$milkType->value]; @endphp
                    @if ($rules->isNotEmpty())
                        <div class="table-responsive border-bottom">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="px-3 pt-2 small">
                                    {{ $milkType->label() }} &mdash; {{ __('pricing.history') }}
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="text-end">{{ __('pricing.columns.rate') }}</th>
                                        <th scope="col">{{ __('pricing.columns.effective_from') }}</th>
                                        <th scope="col">{{ __('pricing.columns.effective_to') }}</th>
                                        <th scope="col" class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rules as $rule)
                                        @php $isFuture = $rule->effective_from->gt(now()->startOfDay()); @endphp
                                        <tr>
                                            <td class="text-end font-monospace">
                                                &#8377;{{ number_format((float) $rule->rate, 2) }}
                                            </td>
                                            <td class="small">{{ $rule->effective_from->translatedFormat('d-m-Y') }}</td>
                                            <td class="small">
                                                {{ $rule->effective_to?->translatedFormat('d-m-Y') ?? '—' }}
                                            </td>
                                            <td class="text-end">
                                                @can('update', $buyer)
                                                    @if ($isFuture)
                                                        <form method="POST"
                                                              action="{{ route('buyers.prices.destroy', [$buyer, $rule]) }}"
                                                              onsubmit="return confirm('{{ __('app.actions.confirm') }}');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                            </button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach

                @can('update', $buyer)
                    <div class="card-body">
                        <h4 class="h6 mb-3">{{ __('pricing.set_new') }}</h4>

                        <form method="POST" action="{{ route('buyers.prices.store', $buyer) }}"
                              class="row g-3 align-items-end" novalidate>
                            @csrf

                            <div class="col-12 col-md-3">
                                <label for="milk_type" class="form-label">{{ __('pricing.fields.milk_type') }} *</label>
                                <select id="milk_type" name="milk_type"
                                        class="form-select form-select-sm @error('milk_type') is-invalid @enderror" required>
                                    @foreach ($milkTypes as $type)
                                        <option value="{{ $type->value }}" @selected(old('milk_type') === $type->value)>
                                            {{ $type->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('milk_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-3">
                                <label for="rate" class="form-label">{{ __('pricing.fields.rate') }} *</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">&#8377;</span>
                                    <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                           id="rate" name="rate" value="{{ old('rate') }}"
                                           class="form-control font-monospace text-end @error('rate') is-invalid @enderror"
                                           required>
                                    @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="effective_from" class="form-label">
                                    {{ __('pricing.fields.effective_from') }} *
                                </label>
                                <input type="date" id="effective_from" name="effective_from"
                                       value="{{ old('effective_from') }}"
                                       class="form-control form-control-sm @error('effective_from') is-invalid @enderror"
                                       required>
                                @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary">{{ __('app.actions.save') }}</button>
                            </div>
                        </form>
                    </div>
                @endcan
            </div>
        </div>
    </div>
@endsection
