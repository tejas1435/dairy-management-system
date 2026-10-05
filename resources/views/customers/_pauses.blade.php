<div class="card shadow-sm h-100">
    <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h3 class="h6 mb-0">{{ __('customers.pauses.title') }}</h3>
            <div class="small text-body-secondary">{{ __('customers.pauses.help') }}</div>
        </div>

        @if ($pauses['current'])
            <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                {{ __('customers.pauses.current') }}
            </span>
        @endif
    </div>

    @can('update', $customer)
        <div class="card-body border-bottom">
            <form method="POST" action="{{ route('customers.pauses.store', $customer) }}" class="row g-2 align-items-end">
                @csrf

                <div class="col-6 col-md-3">
                    <label for="pause-start" class="form-label small mb-1">{{ __('customers.fields.date') }}</label>
                    <input type="date" class="form-control form-control-sm @error('start_date') is-invalid @enderror"
                           id="pause-start" name="start_date" value="{{ old('start_date') }}" required>
                    @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-6 col-md-3">
                    <label for="pause-end" class="form-label small mb-1">
                        {{ __('customers.fields.end_date') }}
                    </label>
                    {{-- Left empty means open-ended: paused until somebody says otherwise. --}}
                    <input type="date" class="form-control form-control-sm @error('end_date') is-invalid @enderror"
                           id="pause-end" name="end_date" value="{{ old('end_date') }}">
                    @error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-4">
                    <label for="pause-reason" class="form-label small mb-1">
                        {{ __('customers.fields.reason') }}
                    </label>
                    <input type="text" class="form-control form-control-sm @error('reason') is-invalid @enderror"
                           id="pause-reason" name="reason" value="{{ old('reason') }}" maxlength="1000">
                    @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        {{ __('customers.pauses.add') }}
                    </button>
                </div>
            </form>
        </div>
    @endcan

    @php
        $groups = [
            'current' => $pauses['current'] ? collect([$pauses['current']]) : collect(),
            'upcoming' => $pauses['upcoming'],
            'past' => $pauses['past'],
            'cancelled' => $pauses['cancelled'],
        ];
        $hasAny = collect($groups)->contains(fn ($g) => $g->isNotEmpty());
    @endphp

    @if (! $hasAny)
        <div class="card-body">
            <x-empty-state icon="pause-circle" :title="__('customers.pauses.none')"
                           :description="__('customers.pauses.subtitle')" />
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('customers.pauses.title') }}</th>
                        <th scope="col">{{ __('customers.fields.reason') }}</th>
                        <th scope="col"></th>
                        <th scope="col" class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $label => $group)
                        @foreach ($group as $pause)
                            <tr class="{{ $pause->isCancelled() ? 'text-body-secondary' : '' }}">
                                <td class="small text-nowrap">
                                    {{ __('customers.pauses.from_to', [
                                        'from' => $pause->start_date->translatedFormat('d-m-Y'),
                                        'to' => $pause->end_date?->translatedFormat('d-m-Y')
                                            ?? __('customers.pauses.open_ended'),
                                    ]) }}
                                </td>
                                <td class="small">
                                    {{ $pause->reason ?? '—' }}
                                    @if ($pause->cancellation_reason)
                                        <div class="text-danger-emphasis">{{ $pause->cancellation_reason }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $pause->stateBadge($today) }}">
                                        {{ __('customers.pauses.'.($pause->isCancelled() ? 'withdrawn' : $label)) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @if (! $pause->isCancelled())
                                        @can('update', $customer)
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancel-pause-{{ $pause->id }}">
                                                {{ __('app.actions.cancel') }}
                                            </button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@php
    // Every pause that can still be withdrawn: the current one plus anything not
    // already cancelled.
    $cancellable = collect($groups)
        ->except('cancelled')
        ->flatten()
        ->reject(fn ($pause) => $pause->isCancelled());
@endphp

@can('update', $customer)
    @foreach ($cancellable as $pause)
        <div class="modal fade" id="cancel-pause-{{ $pause->id }}" tabindex="-1"
             aria-labelledby="cancel-pause-label-{{ $pause->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('customers.pauses.cancel', $pause) }}" class="modal-content">
                    @csrf
                    @method('PUT')

                    <div class="modal-header">
                        <h5 class="modal-title h6" id="cancel-pause-label-{{ $pause->id }}">
                            {{ __('customers.pauses.cancel_title') }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('app.actions.close') }}"></button>
                    </div>

                    <div class="modal-body">
                        <p class="small text-body-secondary">{{ __('customers.pauses.cancel_help') }}</p>

                        <dl class="row small mb-3">
                            <dt class="col-5">{{ __('customers.pauses.title') }}</dt>
                            <dd class="col-7">
                                {{ __('customers.pauses.from_to', [
                                    'from' => $pause->start_date->translatedFormat('d-m-Y'),
                                    'to' => $pause->end_date?->translatedFormat('d-m-Y')
                                        ?? __('customers.pauses.open_ended'),
                                ]) }}
                            </dd>
                        </dl>

                        <label for="pause-cancel-reason-{{ $pause->id }}" class="form-label">
                            {{ __('customers.fields.cancellation_reason') }}
                        </label>
                        <textarea class="form-control" id="pause-cancel-reason-{{ $pause->id }}"
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
