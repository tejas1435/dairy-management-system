@extends('layouts.app')

@section('title', __('pricing.title'))

@section('header')
    <x-page-header :title="__('pricing.title')" :subtitle="__('pricing.subtitle')" />
@endsection

@section('content')
    {{--
        Presented as history, not as one editable number. Setting a price opens
        a new period and closes the current one; it never overwrites a row, so a
        sale dated in a closed period keeps the rate that applied then.
    --}}
    <div class="alert alert-light border d-flex align-items-start gap-2 small" role="note">
        <i class="bi bi-clock-history flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ __('pricing.help.append_only') }}</div>
    </div>

    <div class="row g-3">
        @foreach ($milkTypes as $milkType)
            @php
                // Distinct names: reassigning $current here would clobber the
                // array on the first pass and break the second milk type.
                $currentPrice = $current[$milkType->value];
                $rules = $history[$milkType->value];
            @endphp

            <div class="col-12 col-xl-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="h6 mb-0">{{ $milkType->label() }}</h3>
                            <div class="small text-body-secondary">{{ __('pricing.current') }}</div>
                        </div>
                        <div class="text-end">
                            @if ($currentPrice->found)
                                <div class="fs-5 fw-semibold font-monospace">
                                    &#8377;{{ number_format((float) $currentPrice->rate, 2) }}
                                </div>
                            @else
                                <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                    {{ __('pricing.no_current') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="text-end">{{ __('pricing.columns.rate') }}</th>
                                    <th scope="col">{{ __('pricing.columns.effective_from') }}</th>
                                    <th scope="col">{{ __('pricing.columns.effective_to') }}</th>
                                    <th scope="col">{{ __('pricing.columns.status') }}</th>
                                    <th scope="col" class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rules as $rule)
                                    @php
                                        $from = $rule->effective_from;
                                        $isFuture = $from->gt(now()->startOfDay());
                                        $state = $isFuture ? 'future' : ($rule->isOpenEnded() ? 'open' : 'closed');
                                    @endphp
                                    <tr>
                                        <td class="text-end font-monospace">
                                            &#8377;{{ number_format((float) $rule->rate, 2) }}
                                        </td>
                                        <td class="small">{{ $from->translatedFormat('d-m-Y') }}</td>
                                        <td class="small">
                                            {{ $rule->effective_to?->translatedFormat('d-m-Y') ?? '—' }}
                                        </td>
                                        <td>
                                            <span class="badge {{ match ($state) {
                                                'open' => 'text-success-emphasis bg-success-subtle border border-success-subtle',
                                                'future' => 'text-primary-emphasis bg-primary-subtle border border-primary-subtle',
                                                default => 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle',
                                            } }}">
                                                {{ __('pricing.states.'.$state) }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            {{-- Only a period that has not started can be
                                                 withdrawn; anything in effect may already
                                                 have priced a sale. --}}
                                            @if ($isFuture)
                                                <form method="POST"
                                                      action="{{ route('settings.milk-prices.destroy', $rule) }}"
                                                      onsubmit="return confirm('{{ __('app.actions.confirm') }}');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
                                            <x-empty-state icon="tag" :description="__('pricing.no_current')" />
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-1">{{ __('pricing.set_new') }}</h3>
                    <p class="small text-body-secondary mb-3">{{ __('pricing.help.forward_only') }}</p>

                    <form method="POST" action="{{ route('settings.milk-prices.store') }}"
                          class="row g-3 align-items-end" novalidate>
                        @csrf

                        <div class="col-12 col-md-3">
                            <label for="milk_type" class="form-label">{{ __('pricing.fields.milk_type') }} *</label>
                            <select id="milk_type" name="milk_type"
                                    class="form-select @error('milk_type') is-invalid @enderror" required>
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
                            <div class="input-group">
                                <span class="input-group-text">&#8377;</span>
                                <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                       id="rate" name="rate" value="{{ old('rate') }}"
                                       class="form-control font-monospace text-end @error('rate') is-invalid @enderror"
                                       required>
                                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="effective_from" class="form-label">
                                {{ __('pricing.fields.effective_from') }} *
                            </label>
                            <input type="date" id="effective_from" name="effective_from"
                                   value="{{ old('effective_from') }}"
                                   class="form-control @error('effective_from') is-invalid @enderror" required>
                            @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
