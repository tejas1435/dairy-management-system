@extends('layouts.app')

@section('title', __('milk.adjustments.title'))

@section('header')
    <x-page-header :title="__('milk.adjustments.title')" :subtitle="__('milk.adjustments.subtitle')" />
@endsection

@section('content')
    {{--
        Presented as the exception it is, not as another entry screen.

        Two things this view must never do: pre-fill a quantity, and offer a
        shortcut that makes remaining come out at zero. An adjustment is somebody's
        statement about what was actually available, and a pre-filled figure would
        turn that statement into a button. The reconciliation context below is there
        so the person can see the position before deciding -- not so the screen can
        decide for them.
    --}}
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>
            <div class="small">{{ __('milk.adjustments.warning') }}</div>
            <div class="small fw-medium mt-1">{{ __('milk.adjustments.no_prefill') }}</div>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="{{ __('milk.fields.date') }}">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.adjustments.index', ['date' => $previousDate]) }}">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.previous_day') }}</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('milk.adjustments.index') }}">
                {{ __('milk.production.today') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.adjustments.index', ['date' => $nextDate]) }}">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.next_day') }}</span>
            </a>
        </div>

        <form method="GET" action="{{ route('milk.adjustments.index') }}" class="d-flex align-items-center gap-2">
            <label for="date-filter" class="form-label mb-0 small">{{ __('milk.fields.date') }}</label>
            <input type="date" class="form-control form-control-sm" style="width: auto"
                   id="date-filter" name="date" value="{{ $date->toDateString() }}">
            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('app.actions.filter') }}</button>
        </form>
    </div>

    <x-validation-errors />

    <div class="row g-3">
        <div class="col-12 col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-0">{{ __('milk.adjustments.context', ['date' => $date->translatedFormat('d-m-Y')]) }}</h3>
                    <div class="small text-body-secondary">{{ $farm->name }}</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('milk.fields.shift') }}</th>
                                <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                                <th scope="col" class="text-end">{{ __('milk.reconciliation.production') }}</th>
                                <th scope="col" class="text-end">{{ __('milk.reconciliation.allocated') }}</th>
                                <th scope="col" class="text-end">{{ __('milk.reconciliation.remaining') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($shifts as $shift)
                                @foreach ($milkTypes as $milkType)
                                    @php $result = $reconciliation[$shift->value][$milkType->value]; @endphp
                                    <tr>
                                        <td class="small">{{ $shift->label() }}</td>
                                        <td class="small">{{ $milkType->label() }}</td>
                                        <td class="text-end">
                                            @if ($result->productionEntered)
                                                <x-litres :quantity="$result->production" :unit="false" />
                                            @else
                                                <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                                    {{ __('milk.not_entered_short') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <x-litres :quantity="$result->allocated" :unit="false" muted />
                                        </td>
                                        <td class="text-end">
                                            <span class="badge {{ $result->remainingBadge() }}">
                                                <x-litres :quantity="$result->remaining" :unit="false" />
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm h-100 border-warning-subtle">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-0">{{ __('milk.adjustments.record') }}</h3>
                </div>

                <div class="card-body">
                    <form method="POST" action="{{ route('milk.adjustments.store') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="adjustment_date" value="{{ $date->toDateString() }}">

                        <div class="col-12 col-md-6">
                            <label for="shift" class="form-label">{{ __('milk.fields.shift') }}</label>
                            <select class="form-select @error('shift') is-invalid @enderror"
                                    id="shift" name="shift" required>
                                @foreach ($shifts as $option)
                                    <option value="{{ $option->value }}" @selected(old('shift') === $option->value)>
                                        {{ $option->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="milk_type" class="form-label">{{ __('milk.fields.milk_type') }}</label>
                            <select class="form-select @error('milk_type') is-invalid @enderror"
                                    id="milk_type" name="milk_type" required>
                                @foreach ($milkTypes as $option)
                                    <option value="{{ $option->value }}" @selected(old('milk_type') === $option->value)>
                                        {{ $option->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('milk_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="direction" class="form-label">{{ __('milk.fields.direction') }}</label>
                            <select class="form-select @error('direction') is-invalid @enderror"
                                    id="direction" name="direction" required>
                                @foreach ($directions as $option)
                                    <option value="{{ $option->value }}" @selected(old('direction') === $option->value)>
                                        {{ $option->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('direction')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="quantity" class="form-label">{{ __('milk.fields.quantity') }}</label>
                            {{-- No value attribute beyond old(): never pre-filled. --}}
                            <input type="number" class="form-control text-end font-monospace @error('quantity') is-invalid @enderror"
                                   id="quantity" name="quantity" value="{{ old('quantity') }}"
                                   step="0.001" min="0.001" max="9999999.999"
                                   inputmode="decimal" placeholder="0.000" required>
                            @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label for="reason" class="form-label">{{ __('milk.fields.reason') }}</label>
                            <textarea class="form-control @error('reason') is-invalid @enderror"
                                      id="reason" name="reason" rows="3" required
                                      minlength="5" maxlength="1000">{{ old('reason') }}</textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-sliders me-1" aria-hidden="true"></i>{{ __('milk.adjustments.record') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-body border-bottom">
            <h3 class="h6 mb-0">{{ __('milk.adjustments.history', ['date' => $date->translatedFormat('d-m-Y')]) }}</h3>
        </div>

        @if ($adjustments->isEmpty())
            <div class="card-body">
                <x-empty-state icon="sliders" :title="__('milk.adjustments.no_adjustments')"
                               :description="__('milk.adjustments.subtitle')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('milk.fields.shift') }}</th>
                            <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                            <th scope="col">{{ __('milk.fields.direction') }}</th>
                            <th scope="col" class="text-end">{{ __('milk.fields.quantity') }}</th>
                            <th scope="col">{{ __('milk.fields.reason') }}</th>
                            <th scope="col">{{ __('milk.fields.recorded_by') }}</th>
                            <th scope="col" class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adjustments as $adjustment)
                            <tr class="{{ $adjustment->isCancelled() ? 'text-body-secondary' : '' }}">
                                <td class="small">{{ $adjustment->shift->label() }}</td>
                                <td class="small">{{ $adjustment->milk_type->label() }}</td>
                                <td>
                                    <span class="badge {{ $adjustment->direction->badge() }}">
                                        <i class="bi bi-{{ $adjustment->direction->icon() }} me-1" aria-hidden="true"></i>
                                        {{ $adjustment->direction->label() }}
                                    </span>
                                    @if ($adjustment->isCancelled())
                                        <span class="badge text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                            {{ __('finance.statuses.cancelled') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <x-litres :quantity="$adjustment->quantity" :muted="$adjustment->isCancelled()" />
                                </td>
                                <td class="small">
                                    {{ $adjustment->reason }}
                                    @if ($adjustment->cancellation_reason)
                                        <div class="text-danger-emphasis">{{ $adjustment->cancellation_reason }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $adjustment->creator?->name ?? '—' }}</td>
                                <td class="text-end">
                                    @if (! $adjustment->isCancelled())
                                        @can('milk.adjustment.cancel')
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancel-adjustment-{{ $adjustment->id }}">
                                                {{ __('app.actions.cancel') }}
                                            </button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @can('milk.adjustment.cancel')
        @foreach ($adjustments->reject->isCancelled() as $adjustment)
            <div class="modal fade" id="cancel-adjustment-{{ $adjustment->id }}" tabindex="-1"
                 aria-labelledby="cancel-adjustment-label-{{ $adjustment->id }}" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('milk.adjustments.cancel', $adjustment) }}"
                          class="modal-content">
                        @csrf
                        @method('PUT')

                        <div class="modal-header">
                            <h5 class="modal-title h6" id="cancel-adjustment-label-{{ $adjustment->id }}">
                                {{ __('milk.adjustments.cancel_title') }}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="{{ __('app.actions.close') }}"></button>
                        </div>

                        <div class="modal-body">
                            <p class="small text-body-secondary">{{ __('milk.adjustments.cancel_help') }}</p>

                            <dl class="row small mb-3">
                                <dt class="col-5">{{ __('milk.fields.direction') }}</dt>
                                <dd class="col-7">{{ $adjustment->direction->label() }}</dd>
                                <dt class="col-5">{{ __('milk.fields.quantity') }}</dt>
                                <dd class="col-7"><x-litres :quantity="$adjustment->quantity" /></dd>
                                <dt class="col-5">{{ __('milk.fields.reason') }}</dt>
                                <dd class="col-7">{{ $adjustment->reason }}</dd>
                            </dl>

                            <label for="adjustment-reason-{{ $adjustment->id }}" class="form-label">
                                {{ __('milk.fields.cancellation_reason') }}
                            </label>
                            <textarea class="form-control" id="adjustment-reason-{{ $adjustment->id }}"
                                      name="cancellation_reason" rows="3" required minlength="5"
                                      maxlength="1000"></textarea>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                {{ __('app.actions.close') }}
                            </button>
                            <button type="submit" class="btn btn-danger">{{ __('app.actions.confirm') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    @endcan
@endsection
