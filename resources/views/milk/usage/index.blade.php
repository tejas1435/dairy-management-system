@extends('layouts.app')

@section('title', __('milk.usage.title'))

@section('header')
    <x-page-header :title="__('milk.usage.title')" :subtitle="__('milk.usage.subtitle')" />
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="{{ __('milk.fields.date') }}">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.usage.index', ['date' => $previousDate]) }}">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.previous_day') }}</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('milk.usage.index') }}">
                {{ __('milk.production.today') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('milk.usage.index', ['date' => $nextDate]) }}">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('milk.production.next_day') }}</span>
            </a>
        </div>

        <form method="GET" action="{{ route('milk.usage.index') }}" class="d-flex align-items-center gap-2">
            <label for="date-filter" class="form-label mb-0 small">{{ __('milk.fields.date') }}</label>
            <input type="date" class="form-control form-control-sm" style="width: auto"
                   id="date-filter" name="date" value="{{ $date->toDateString() }}">
            <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('app.actions.filter') }}</button>
        </form>
    </div>

    <x-validation-errors />

    <div class="row g-3">
        {{--
            The remaining-milk panel sits beside the form on purpose: "how much can
            I record" is only answerable next to "how much is left". The figures come
            from the reconciliation engine, and the server re-checks them inside the
            save transaction regardless of what is shown here.
        --}}
        <div class="col-12 col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-0">{{ __('milk.reconciliation.remaining') }}</h3>
                    <div class="small text-body-secondary">{{ __('milk.usage.available_help') }}</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('milk.fields.shift') }}</th>
                                <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                                <th scope="col" class="text-end">{{ __('milk.reconciliation.available') }}</th>
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
                                                <x-litres :quantity="$result->available" :unit="false" />
                                            @else
                                                <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                                    {{ __('milk.not_entered_short') }}
                                                </span>
                                            @endif
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

        @can('milk.usage.create')
            <div class="col-12 col-xl-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body border-bottom">
                        <h3 class="h6 mb-0">{{ __('milk.usage.record') }}</h3>
                        <div class="small text-body-secondary">{{ __('milk.usage.help') }}</div>
                    </div>

                    <div class="card-body">
                        <form method="POST" action="{{ route('milk.usage.store') }}" class="row g-3">
                            @csrf
                            <input type="hidden" name="usage_date" value="{{ $date->toDateString() }}">

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
                                <label for="usage_type" class="form-label">{{ __('milk.fields.usage_type') }}</label>
                                <select class="form-select @error('usage_type') is-invalid @enderror"
                                        id="usage_type" name="usage_type" required>
                                    @foreach ($usageTypes as $option)
                                        <option value="{{ $option->value }}" @selected(old('usage_type') === $option->value)>
                                            {{ $option->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('usage_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="quantity" class="form-label">{{ __('milk.fields.quantity') }}</label>
                                <input type="number" class="form-control text-end font-monospace @error('quantity') is-invalid @enderror"
                                       id="quantity" name="quantity" value="{{ old('quantity') }}"
                                       step="0.001" min="0.001" max="9999999.999"
                                       inputmode="decimal" placeholder="0.000" required>
                                @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12">
                                <label for="notes" class="form-label">{{ __('milk.fields.notes') }}</label>
                                <textarea class="form-control @error('notes') is-invalid @enderror"
                                          id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('milk.usage.record') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endcan
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-body border-bottom">
            <h3 class="h6 mb-0">{{ __('milk.usage.history', ['date' => $date->translatedFormat('d-m-Y')]) }}</h3>
        </div>

        @if ($usages->isEmpty())
            <div class="card-body">
                <x-empty-state icon="cup-straw" :title="__('milk.usage.no_usage')" :description="__('milk.usage.help')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('milk.fields.shift') }}</th>
                            <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                            <th scope="col">{{ __('milk.fields.usage_type') }}</th>
                            <th scope="col" class="text-end">{{ __('milk.fields.quantity') }}</th>
                            <th scope="col">{{ __('milk.fields.recorded_by') }}</th>
                            <th scope="col" class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($usages as $usage)
                            <tr class="{{ $usage->isCancelled() ? 'text-body-secondary' : '' }}">
                                <td class="small">{{ $usage->shift->label() }}</td>
                                <td class="small">{{ $usage->milk_type->label() }}</td>
                                <td>
                                    <span class="badge {{ $usage->usage_type->badge() }}">
                                        {{ $usage->usage_type->label() }}
                                    </span>
                                    @if ($usage->isCancelled())
                                        <span class="badge text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                            {{ __('finance.statuses.cancelled') }}
                                        </span>
                                    @endif
                                    @if ($usage->notes)
                                        <div class="small text-body-secondary">{{ $usage->notes }}</div>
                                    @endif
                                    @if ($usage->cancellation_reason)
                                        <div class="small text-danger-emphasis">{{ $usage->cancellation_reason }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <x-litres :quantity="$usage->quantity" :muted="$usage->isCancelled()" />
                                </td>
                                <td class="small">{{ $usage->creator?->name ?? '—' }}</td>
                                <td class="text-end">
                                    @if (! $usage->isCancelled())
                                        @can('milk.usage.cancel')
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancel-usage-{{ $usage->id }}">
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

    {{-- Cancellation modals, one per active record. A reason is mandatory. --}}
    @can('milk.usage.cancel')
        @foreach ($usages->reject->isCancelled() as $usage)
            <div class="modal fade" id="cancel-usage-{{ $usage->id }}" tabindex="-1"
                 aria-labelledby="cancel-usage-label-{{ $usage->id }}" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('milk.usage.cancel', $usage) }}" class="modal-content">
                        @csrf
                        @method('PUT')

                        <div class="modal-header">
                            <h5 class="modal-title h6" id="cancel-usage-label-{{ $usage->id }}">
                                {{ __('milk.usage.cancel_title') }}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="{{ __('app.actions.close') }}"></button>
                        </div>

                        <div class="modal-body">
                            <p class="small text-body-secondary">{{ __('milk.usage.cancel_help') }}</p>

                            <dl class="row small mb-3">
                                <dt class="col-5">{{ __('milk.fields.usage_type') }}</dt>
                                <dd class="col-7">{{ $usage->usage_type->label() }}</dd>
                                <dt class="col-5">{{ __('milk.fields.quantity') }}</dt>
                                <dd class="col-7"><x-litres :quantity="$usage->quantity" /></dd>
                            </dl>

                            <label for="usage-reason-{{ $usage->id }}" class="form-label">
                                {{ __('milk.fields.cancellation_reason') }}
                            </label>
                            <textarea class="form-control" id="usage-reason-{{ $usage->id }}"
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
