<div class="card shadow-sm mb-3">
    <div class="card-body border-bottom">
        <h3 class="h6 mb-0">{{ __('customers.prices.title') }}</h3>
        <div class="small text-body-secondary">{{ __('customers.prices.subtitle') }}</div>
    </div>

    <div class="row g-0">
        @foreach ($milkTypes as $milkType)
            @php
                $current = $resolved[$milkType->value];
                $rules = $priceRules[$milkType->value];
            @endphp

            <div class="col-12 col-lg-6 {{ $loop->first ? 'border-end' : '' }}">
                <div class="card-body border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-medium">{{ $milkType->label() }}</span>
                        <div class="small text-body-secondary">
                            @if ($current->found)
                                {{ $current->isFromBuyerOverride()
                                    ? __('customers.prices.using_override')
                                    : __('customers.prices.using_default') }}
                            @else
                                {{ __('pricing.no_current') }}
                            @endif
                        </div>
                    </div>
                    <div class="text-end">
                        @if ($current->found)
                            <span class="font-monospace fs-6">&#8377;{{ number_format((float) $current->rate, 2) }}</span>
                        @else
                            <span class="badge text-warning-emphasis bg-warning-subtle border border-warning-subtle">
                                {{ __('pricing.no_current') }}
                            </span>
                        @endif
                    </div>
                </div>

                @if ($rules->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
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
                                    @php
                                        $isFuture = $rule->effective_from->gt(now()->startOfDay());
                                    @endphp
                                    <tr>
                                        <td class="text-end font-monospace">
                                            &#8377;{{ number_format((float) $rule->rate, 2) }}
                                        </td>
                                        <td class="small">{{ $rule->effective_from->translatedFormat('d-m-Y') }}</td>
                                        <td class="small">
                                            {{ $rule->effective_to?->translatedFormat('d-m-Y') ?? '—' }}
                                        </td>
                                        <td class="text-end">
                                            {{-- Only a period that has not started may be withdrawn. --}}
                                            @if ($isFuture)
                                                @can('update', $customer)
                                                    <form method="POST"
                                                          action="{{ route('customers.prices.destroy', [$customer, $rule]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            {{ __('app.actions.delete') }}
                                                        </button>
                                                    </form>
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
        @endforeach
    </div>

    @can('update', $customer)
        <div class="card-body border-top">
            <form method="POST" action="{{ route('customers.prices.store', $customer) }}"
                  class="row g-2 align-items-end">
                @csrf

                <div class="col-6 col-md-3">
                    <label for="price-milk-type" class="form-label small mb-1">
                        {{ __('milk.fields.milk_type') }}
                    </label>
                    <select class="form-select form-select-sm" id="price-milk-type" name="milk_type" required>
                        @foreach ($milkTypes as $milkType)
                            <option value="{{ $milkType->value }}">{{ $milkType->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label for="price-rate" class="form-label small mb-1">{{ __('pricing.fields.rate') }}</label>
                    <input type="number" class="form-control form-control-sm text-end font-monospace @error('rate') is-invalid @enderror"
                           id="price-rate" name="rate" value="{{ old('rate') }}"
                           step="0.01" min="0.01" inputmode="decimal" required>
                    @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-8 col-md-4">
                    <label for="price-from" class="form-label small mb-1">
                        {{ __('pricing.fields.effective_from') }}
                    </label>
                    <input type="date" class="form-control form-control-sm @error('effective_from') is-invalid @enderror"
                           id="price-from" name="effective_from" value="{{ old('effective_from') }}" required>
                    @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-4 col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        {{ __('customers.prices.set') }}
                    </button>
                </div>
            </form>
        </div>
    @endcan
</div>
