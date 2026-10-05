@extends('layouts.app')

@section('title', __('milk.production.title'))

@section('header')
    <x-page-header :title="__('milk.production.title')" :subtitle="__('milk.production.subtitle')" />
@endsection

@section('content')
    {{--
        The matrix the specification asks for: milk types down, shifts across.

        The table underneath holds one row per shift, so this screen saves up to
        two records in one transaction. The two shapes are deliberately different:
        the entry grid is for the person, the row-per-shift storage is for the
        reconciliation engine, which asks about one shift at a time.
    --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="{{ __('milk.fields.date') }}">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.production.index', ['date' => $previousDate]) }}">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.previous_day') }}</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.production.index', ['date' => $today]) }}">
                {{ __('milk.production.today') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.production.index', ['date' => $nextDate]) }}">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.next_day') }}</span>
            </a>
        </div>

        <form method="GET" action="{{ route('milk.production.index') }}" class="d-flex align-items-center gap-2">
            <label for="date-filter" class="form-label mb-0 small">{{ __('milk.fields.date') }}</label>
            <input type="date" class="form-control form-control-sm" style="width: auto"
                   id="date-filter" name="date" value="{{ $date->toDateString() }}">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                {{ __('app.actions.filter') }}
            </button>
        </form>
    </div>

    <x-validation-errors />

    <form method="POST" action="{{ route('milk.production.store') }}">
        @csrf
        <input type="hidden" name="production_date" value="{{ $date->toDateString() }}">

        <div class="card shadow-sm mb-3">
            <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="h6 mb-0">{{ __('milk.production.matrix_caption', ['date' => $date->translatedFormat('d-m-Y')]) }}</h3>
                    <div class="small text-body-secondary">{{ $farm->name }}</div>
                </div>
                <div class="text-end">
                    <div class="small text-body-secondary">{{ __('milk.total') }}</div>
                    <div class="fs-5"><x-litres :quantity="$grandTotal" strong /></div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">
                        {{ __('milk.production.matrix_caption', ['date' => $date->translatedFormat('d-m-Y')]) }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                            @foreach ($shifts as $shift)
                                <th scope="col" class="text-end">
                                    <i class="bi bi-{{ $shift->icon() }} me-1" aria-hidden="true"></i>{{ $shift->label() }}
                                </th>
                            @endforeach
                            <th scope="col" class="text-end">{{ __('milk.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($milkTypes as $milkType)
                            <tr>
                                <th scope="row" class="fw-medium">{{ $milkType->label() }}</th>

                                @foreach ($shifts as $shift)
                                    @php
                                        $record = $records[$shift->value];
                                        $field = "shifts.{$shift->value}.".$milkType->value;
                                        $stored = $record?->quantityFor($milkType);
                                    @endphp
                                    <td class="text-end">
                                        <label class="visually-hidden" for="{{ $shift->value }}-{{ $milkType->value }}">
                                            {{ $shift->label() }} — {{ $milkType->label() }}
                                        </label>
                                        <input type="number"
                                               class="form-control form-control-sm text-end font-monospace ms-auto @error($field) is-invalid @enderror"
                                               style="max-width: 9rem"
                                               id="{{ $shift->value }}-{{ $milkType->value }}"
                                               name="shifts[{{ $shift->value }}][{{ $milkType->value }}]"
                                               value="{{ old($field, $stored) }}"
                                               step="0.001" min="0" max="9999999.999"
                                               inputmode="decimal"
                                               placeholder="0.000">
                                        @error($field)
                                            <div class="invalid-feedback d-block text-start small">{{ $message }}</div>
                                        @enderror
                                    </td>
                                @endforeach

                                <td class="text-end">
                                    <x-litres :quantity="$typeTotals[$milkType->value]" muted />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th scope="row">{{ __('milk.total') }}</th>
                            @foreach ($shifts as $shift)
                                <td class="text-end">
                                    {{--
                                        A shift with no record shows an em-dash, not
                                        0.000. Nobody has stated what this shift
                                        produced, and printing a zero would claim
                                        they had.
                                    --}}
                                    <x-litres :quantity="$shiftTotals[$shift->value]" strong />
                                </td>
                            @endforeach
                            <td class="text-end"><x-litres :quantity="$grandTotal" strong /></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="row g-3 mb-3">
            @foreach ($shifts as $shift)
                @php
                    $record = $records[$shift->value];
                    $notesField = "shifts.{$shift->value}.notes";
                @endphp
                <div class="col-12 col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <label for="notes-{{ $shift->value }}" class="form-label small fw-medium">
                                {{ __('milk.production.shift_notes', ['shift' => $shift->label()]) }}
                            </label>
                            <textarea class="form-control form-control-sm @error($notesField) is-invalid @enderror"
                                      id="notes-{{ $shift->value }}"
                                      name="shifts[{{ $shift->value }}][notes]"
                                      rows="2"
                                      maxlength="1000">{{ old($notesField, $record?->notes) }}</textarea>
                            @error($notesField)
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            <div class="small text-body-secondary mt-2">
                                @if ($record)
                                    @if ($record->creator)
                                        {{ __('milk.production.entered_by', ['name' => $record->creator->name]) }}
                                    @endif
                                    @if ($record->updater && $record->updated_at?->ne($record->created_at))
                                        · {{ __('milk.production.updated_by', ['name' => $record->updater->name]) }}
                                    @endif
                                @else
                                    <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                        {{ __('milk.not_entered') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="alert alert-light border d-flex align-items-start gap-2 small" role="note">
            <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>{{ __('milk.production.help') }}</div>
        </div>

        @can('milk.production.create')
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2 me-1" aria-hidden="true"></i>{{ __('milk.production.save') }}
                </button>
            </div>
        @endcan
    </form>
@endsection
