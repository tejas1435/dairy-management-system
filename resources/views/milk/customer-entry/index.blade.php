@extends('layouts.app')

@section('title', __('milk.customer_entry.title'))

@section('header')
    <x-page-header :title="__('milk.customer_entry.title')" :subtitle="__('milk.customer_entry.subtitle')" />
@endsection

@section('content')
    {{--
        The grid MASTER_SPEC section 19 describes: customers down, shifts across, one
        save for the whole day.

        Two rules are load-bearing here and are easy to break by accident.

        First, the quantity inputs are rendered from `savedQuantity()` and from
        nothing else. The reminder appears in its own column as text. There is no
        branch anywhere in this file that falls back to a reminder when a saved
        quantity is absent — an empty field is the correct and intended state.

        Second, filtering hides rows with CSS only. A hidden row keeps its inputs,
        its name attributes and its place in the payload, so filtering the table
        can never be mistaken for clearing it.
    --}}
    @php
        $morning = \App\Enums\Shift::Morning;
        $evening = \App\Enums\Shift::Evening;
        $canSave = $canCreate || $canUpdate;
    @endphp

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="{{ __('milk.fields.date') }}">
            <a class="btn btn-sm btn-outline-secondary" data-entry-nav
               href="{{ route('milk.customer-entry.index', ['date' => $previousDate]) }}">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.customer_entry.previous_day') }}</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary" data-entry-nav
               href="{{ route('milk.customer-entry.index', ['date' => $today]) }}">
                {{ __('milk.customer_entry.today') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary" data-entry-nav
               href="{{ route('milk.customer-entry.index', ['date' => $nextDate]) }}">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.customer_entry.next_day') }}</span>
            </a>
        </div>

        <form method="GET" action="{{ route('milk.customer-entry.index') }}"
              class="d-flex align-items-center gap-2" data-entry-date-form>
            <label for="date-filter" class="form-label mb-0 small">{{ __('milk.fields.date') }}</label>
            <input type="date" class="form-control form-control-sm" style="width: auto"
                   id="date-filter" name="date" value="{{ $date->toDateString() }}">
            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('app.actions.filter') }}</button>
        </form>
    </div>

    <x-validation-errors />

    {{--
        Every string the JavaScript shows is passed in here rather than written in
        the module, so the grid speaks Gujarati and Hindi like the rest of the
        application. A literal in a .js file is a string no translator ever sees.
    --}}
    <div data-daily-entry
         data-save-url="{{ route('milk.customer-entry.store') }}"
         data-copy-url="{{ route('milk.customer-entry.copy-previous', ['date' => $date->toDateString()]) }}"
         data-date="{{ $date->toDateString() }}"
         data-can-save="{{ $canSave ? '1' : '0' }}"
         data-unit="{{ __('milk.litres_short') }}"
         data-save-day="{{ __('milk.customer_entry.save_day') }}"
         data-saving="{{ __('milk.customer_entry.saving') }}"
         data-save-failed="{{ __('milk.customer_entry.save_failed') }}"
         data-network-failed="{{ __('milk.customer_entry.network_failed') }}"
         data-unsaved-warning="{{ __('milk.customer_entry.unsaved_warning') }}"
         data-copy-confirm="{{ __('milk.customer_entry.copy_confirm') }}"
         data-copy-done="{{ __('milk.customer_entry.copy_done', ['count' => ':count', 'date' => \Illuminate\Support\Carbon::parse($previousDate)->translatedFormat('d-m-Y')]) }}"
         data-copy-none="{{ __('milk.customer_entry.copy_none', ['date' => \Illuminate\Support\Carbon::parse($previousDate)->translatedFormat('d-m-Y')]) }}"
         data-status-saved="{{ __('milk.customer_entry.statuses.saved') }}"
         data-status-modified="{{ __('milk.customer_entry.statuses.modified') }}"
         data-status-empty="{{ __('milk.customer_entry.statuses.empty') }}">

        {{-- Live region for save results and copy feedback. --}}
        <div class="mb-3" data-entry-feedback aria-live="polite"></div>

        @unless ($canSave)
            <div class="alert alert-secondary py-2 small" role="note">
                <i class="bi bi-eye me-1" aria-hidden="true"></i>{{ __('milk.customer_entry.read_only') }}
            </div>
        @endunless

        <div class="card shadow-sm mb-3">
            <div class="card-body py-2 border-bottom">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <span class="small text-body-secondary">
                        <i class="bi bi-droplet me-1" aria-hidden="true"></i>{{ __('milk.customer_entry.remaining_milk') }}
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
                <p class="small text-body-secondary mb-0 mt-1">{{ __('milk.customer_entry.remaining_help') }}</p>
            </div>

            <div class="card-body py-2 border-bottom">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label for="entry-search" class="form-label small mb-1">{{ __('milk.customer_entry.search') }}</label>
                        <input type="search" class="form-control form-control-sm" id="entry-search"
                               placeholder="{{ __('milk.customer_entry.search_placeholder') }}"
                               autocomplete="off" data-entry-filter-search>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="entry-type" class="form-label small mb-1">{{ __('milk.fields.milk_type') }}</label>
                        <select class="form-select form-select-sm" id="entry-type" data-entry-filter-type>
                            <option value="">{{ __('milk.customer_entry.all_types') }}</option>
                            @foreach ($milkTypes as $milkType)
                                <option value="{{ $milkType->value }}">{{ $milkType->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($areas !== [])
                        <div class="col-6 col-md-3">
                            <label for="entry-area" class="form-label small mb-1">{{ __('customers.fields.area') }}</label>
                            <select class="form-select form-select-sm" id="entry-area" data-entry-filter-area>
                                <option value="">{{ __('milk.customer_entry.all_areas') }}</option>
                                @foreach ($areas as $area)
                                    <option value="{{ $area }}">{{ $area }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <p class="small text-body-secondary mb-0 mt-2">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('milk.customer_entry.filter_hint') }}
                </p>
            </div>

            @if ($rows->isEmpty())
                <x-empty-state icon="person-slash"
                               :title="__('milk.customer_entry.empty')"
                               :description="__('milk.customer_entry.empty_help')" />
            @else
                <div class="table-responsive app-entry-grid">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">
                            {{ __('milk.customer_entry.title') }} — {{ $date->translatedFormat('d-m-Y') }}
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="app-entry-sticky-col">{{ __('milk.customer_entry.customer') }}</th>
                                <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                                <th scope="col">{{ __('milk.customer_entry.reminder') }}</th>
                                <th scope="col" class="text-end" style="min-width: 7rem">
                                    <i class="bi bi-{{ $morning->icon() }} me-1" aria-hidden="true"></i>{{ $morning->label() }}
                                </th>
                                <th scope="col" class="text-end" style="min-width: 7rem">
                                    <i class="bi bi-{{ $evening->icon() }} me-1" aria-hidden="true"></i>{{ $evening->label() }}
                                </th>
                                <th scope="col" class="text-end">{{ __('milk.customer_entry.rate') }}</th>
                                <th scope="col" class="text-end">{{ __('milk.customer_entry.row_total') }}</th>
                                <th scope="col">{{ __('milk.customer_entry.status') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rows as $row)
                                @include('milk.customer-entry._row', ['row' => $row, 'canSave' => $canSave])
                            @endforeach

                            <tr class="d-none" data-entry-no-matches>
                                <td colspan="8" class="text-center text-body-secondary py-4">
                                    {{ __('milk.customer_entry.no_matches') }}
                                </td>
                            </tr>
                        </tbody>

                        <tfoot class="table-light">
                            <tr>
                                <th scope="row" colspan="3" class="app-entry-sticky-col">
                                    {{ __('milk.customer_entry.totals') }}
                                </th>
                                @foreach ($shifts as $shift)
                                    <td class="text-end">
                                        @foreach ($milkTypes as $milkType)
                                            <div class="small">
                                                <span class="text-body-secondary">{{ $milkType->label() }}</span>
                                                <x-litres :quantity="$totals['shifts'][$shift->value][$milkType->value]"
                                                          :unit="false"
                                                          data-total-shift="{{ $shift->value }}"
                                                          data-total-type="{{ $milkType->value }}" />
                                            </div>
                                        @endforeach
                                    </td>
                                @endforeach
                                <td class="text-end">
                                    <div class="small text-body-secondary">{{ __('milk.customer_entry.overall_amount') }}</div>
                                    <x-money :amount="$totals['amount']" data-total-amount />
                                </td>
                                <td class="text-end">
                                    <div class="small text-body-secondary">{{ __('milk.customer_entry.overall_quantity') }}</div>
                                    <x-litres :quantity="$totals['quantity']" strong data-total-quantity />
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="card-body border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <p class="small text-body-secondary mb-0">{{ __('milk.customer_entry.totals_preview') }}</p>

                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-warning d-none" data-entry-dirty>
                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>{{ __('milk.customer_entry.unsaved') }}
                        </span>

                        @if ($canSave)
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-entry-copy>
                                <i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>{{ __('milk.customer_entry.copy_previous') }}
                            </button>
                            <button type="button" class="btn btn-primary" data-entry-save>
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>{{ __('milk.customer_entry.save_day') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        @if ($rows->isNotEmpty())
            <p class="small text-body-secondary">
                {{ __('milk.customer_entry.copy_help', ['date' => \Illuminate\Support\Carbon::parse($previousDate)->translatedFormat('d-m-Y')]) }}
            </p>
        @endif
    </div>
@endsection
