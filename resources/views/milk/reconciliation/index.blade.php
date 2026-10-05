@extends('layouts.app')

@section('title', __('milk.reconciliation.title'))

@section('header')
    <x-page-header :title="__('milk.reconciliation.title')" :subtitle="__('milk.reconciliation.subtitle')" />
@endsection

@section('content')
    {{--
        Every figure on this screen comes from CalculateMilkReconciliation. Nothing
        is added up here.

        The one rule this view exists to honour: when production has not been
        entered, it says so. A shift nobody has recorded and a shift recorded as
        zero produce the same 0.000 litres, and they mean completely different
        things, so the engine keeps them apart and so does this page.
    --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="{{ __('milk.fields.date') }}">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.reconciliation', array_filter(['date' => $previousDate, 'shift' => $selectedShift?->value, 'milk_type' => $selectedMilkType?->value])) }}">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.previous_day') }}</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.reconciliation', array_filter(['shift' => $selectedShift?->value, 'milk_type' => $selectedMilkType?->value])) }}">
                {{ __('milk.production.today') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.reconciliation', array_filter(['date' => $nextDate, 'shift' => $selectedShift?->value, 'milk_type' => $selectedMilkType?->value])) }}">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.next_day') }}</span>
            </a>
        </div>

        <form method="GET" action="{{ route('milk.reconciliation') }}" class="d-flex flex-wrap align-items-end gap-2">
            <div>
                <label for="date" class="form-label mb-0 small">{{ __('milk.fields.date') }}</label>
                <input type="date" class="form-control form-control-sm" id="date" name="date"
                       value="{{ $date->toDateString() }}">
            </div>
            <div>
                <label for="shift" class="form-label mb-0 small">{{ __('milk.fields.shift') }}</label>
                <select class="form-select form-select-sm" id="shift" name="shift">
                    <option value="">{{ __('milk.reconciliation.all_shifts') }}</option>
                    @foreach ($allShifts as $option)
                        <option value="{{ $option->value }}" @selected($selectedShift === $option)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="milk_type" class="form-label mb-0 small">{{ __('milk.fields.milk_type') }}</label>
                <select class="form-select form-select-sm" id="milk_type" name="milk_type">
                    <option value="">{{ __('milk.reconciliation.all_types') }}</option>
                    @foreach ($allMilkTypes as $option)
                        <option value="{{ $option->value }}" @selected($selectedMilkType === $option)>
                            {{ $option->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('app.actions.filter') }}</button>
        </form>
    </div>

    @if ($salesChannels === [])
        {{--
            Truthful rather than convenient. Showing Mandali / Vendors / Direct
            Customers as four rows of 0.000 L would read as "nothing was sold
            today", when the fact is that no sale can have been recorded because the
            modules that record sales do not exist yet.
        --}}
        <div class="alert alert-light border d-flex align-items-start gap-2 small" role="note">
            <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <span class="fw-medium">{{ __('milk.reconciliation.sales_not_implemented') }}.</span>
                {{ __('milk.reconciliation.sales_not_implemented_help') }}
            </div>
        </div>
    @endif

    <div class="row g-3">
        @foreach ($shifts as $shift)
            @foreach ($milkTypes as $milkType)
                @php $result = $results[$shift->value][$milkType->value]; @endphp

                <div class="col-12 col-xl-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <h3 class="h6 mb-0">
                                    <i class="bi bi-{{ $shift->icon() }} me-1" aria-hidden="true"></i>
                                    {{ $shift->label() }} — {{ $milkType->label() }}
                                </h3>
                                <div class="small text-body-secondary">{{ $date->translatedFormat('d-m-Y') }}</div>
                            </div>

                            <div class="text-end">
                                <div class="small text-body-secondary">{{ __('milk.reconciliation.remaining') }}</div>
                                <span class="badge {{ $result->remainingBadge() }} fs-6">
                                    <x-litres :quantity="$result->remaining" />
                                </span>
                            </div>
                        </div>

                        @if (! $result->productionEntered)
                            {{-- The hard rule: not entered, not zero. --}}
                            <div class="card-body border-bottom bg-warning-subtle">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1 text-warning-emphasis"
                                       aria-hidden="true"></i>
                                    <div>
                                        <div class="fw-semibold text-warning-emphasis">
                                            {{ __('milk.not_entered') }}
                                        </div>
                                        <div class="small">{{ __('milk.not_entered_help') }}</div>
                                        @can('milk.production.view')
                                            <a class="small"
                                               href="{{ route('milk.production.index', ['date' => $date->toDateString()]) }}">
                                                {{ __('milk.production.title') }}
                                            </a>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th scope="row" class="fw-normal">{{ __('milk.reconciliation.production') }}</th>
                                        <td class="text-end">
                                            @if ($result->productionEntered)
                                                <x-litres :quantity="$result->production" />
                                            @else
                                                <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                                    {{ __('milk.not_entered_short') }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row" class="fw-normal">
                                            {{ __('milk.reconciliation.adjustment') }}
                                        </th>
                                        <td class="text-end">
                                            <x-litres :quantity="$result->adjustmentTotal" />
                                        </td>
                                    </tr>

                                    @if ($result->hasAdjustments())
                                        <tr class="small text-body-secondary">
                                            <td class="ps-4">{{ __('milk.reconciliation.adjustment_increase') }}</td>
                                            <td class="text-end"><x-litres :quantity="$result->increaseTotal" muted /></td>
                                        </tr>
                                        <tr class="small text-body-secondary">
                                            <td class="ps-4">{{ __('milk.reconciliation.adjustment_decrease') }}</td>
                                            <td class="text-end"><x-litres :quantity="$result->decreaseTotal" muted /></td>
                                        </tr>
                                    @endif

                                    <tr class="table-light">
                                        <th scope="row">{{ __('milk.reconciliation.available') }}</th>
                                        <td class="text-end"><x-litres :quantity="$result->available" strong /></td>
                                    </tr>

                                    <tr>
                                        <th scope="row" class="fw-normal">{{ __('milk.reconciliation.sales') }}</th>
                                        <td class="text-end">
                                            @if ($result->sales->subsystemExists)
                                                <x-litres :quantity="$result->sales->total" />
                                            @else
                                                <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
                                                    {{ __('milk.reconciliation.sales_not_implemented') }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>

                                    @foreach ($salesChannels as $channel)
                                        <tr class="small text-body-secondary">
                                            <td class="ps-4">
                                                {{ __('milk.reconciliation.channels.'.$channel) }}
                                            </td>
                                            <td class="text-end">
                                                <x-litres :quantity="$result->sales->forChannel($channel)" muted />
                                            </td>
                                        </tr>
                                    @endforeach

                                    <tr>
                                        <th scope="row" class="fw-normal">{{ __('milk.reconciliation.internal_usage') }}</th>
                                        <td class="text-end"><x-litres :quantity="$result->usageTotal" /></td>
                                    </tr>

                                    @foreach ($usageTypes as $usageType)
                                        @if ($result->hasUsageOf($usageType))
                                            <tr class="small text-body-secondary">
                                                <td class="ps-4">{{ $usageType->label() }}</td>
                                                <td class="text-end">
                                                    <x-litres :quantity="$result->usageFor($usageType)" muted />
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach

                                    <tr class="table-light">
                                        <th scope="row">{{ __('milk.reconciliation.allocated') }}</th>
                                        <td class="text-end"><x-litres :quantity="$result->allocated" strong /></td>
                                    </tr>

                                    <tr>
                                        <th scope="row">{{ __('milk.reconciliation.remaining') }}</th>
                                        <td class="text-end">
                                            <x-litres :quantity="$result->remaining" strong />
                                            @if ($result->isOverAllocated())
                                                <div class="small text-danger">
                                                    {{ __('milk.reconciliation.over_allocated') }}
                                                </div>
                                            @elseif ($result->isFullyAllocated() && $result->productionEntered)
                                                <div class="small text-success-emphasis">
                                                    {{ __('milk.reconciliation.fully_allocated') }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>

    <div class="alert alert-light border d-flex align-items-start gap-2 small mt-3" role="note">
        <i class="bi bi-calculator flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ __('milk.reconciliation.formula') }}</div>
    </div>
@endsection
