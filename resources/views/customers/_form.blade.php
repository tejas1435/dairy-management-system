@php
    /** @var \App\Models\Buyer|null $customer */
    $customer ??= null;
@endphp

<x-validation-errors />

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body border-bottom">
                <h3 class="h6 mb-0">{{ __('customers.singular') }}</h3>
            </div>
            <div class="card-body row g-3">
                <div class="col-12 col-md-6">
                    <label for="name" class="form-label">{{ __('customers.fields.name') }}</label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror"
                           id="name" name="name" value="{{ old('name', $customer?->name) }}"
                           maxlength="255" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="mobile" class="form-label">{{ __('customers.fields.mobile') }}</label>
                    <input type="text" class="form-control @error('mobile') is-invalid @enderror"
                           id="mobile" name="mobile" value="{{ old('mobile', $customer?->mobile) }}"
                           maxlength="20" inputmode="tel">
                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="area" class="form-label">{{ __('customers.fields.area') }}</label>
                    <input type="text" class="form-control @error('area') is-invalid @enderror"
                           id="area" name="area" value="{{ old('area', $customer?->area) }}" maxlength="255">
                    @error('area')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="email" class="form-label">{{ __('customers.fields.email') }}</label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                           id="email" name="email" value="{{ old('email', $customer?->email) }}" maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="address" class="form-label">{{ __('customers.fields.address') }}</label>
                    <textarea class="form-control @error('address') is-invalid @enderror"
                              id="address" name="address" rows="2" maxlength="1000">{{ old('address', $customer?->address) }}</textarea>
                    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="delivery_note" class="form-label">{{ __('customers.fields.delivery_note') }}</label>
                    <textarea class="form-control @error('delivery_note') is-invalid @enderror"
                              id="delivery_note" name="delivery_note" rows="2"
                              maxlength="1000">{{ old('delivery_note', $customer?->delivery_note) }}</textarea>
                    <div class="form-text">{{ __('customers.help.delivery_note') }}</div>
                    @error('delivery_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="payment_cycle" class="form-label">{{ __('customers.fields.payment_cycle') }}</label>
                    <input type="text" class="form-control @error('payment_cycle') is-invalid @enderror"
                           id="payment_cycle" name="payment_cycle" maxlength="40"
                           value="{{ old('payment_cycle', $customer?->payment_cycle) }}">
                    <div class="form-text">{{ __('customers.help.payment_cycle') }}</div>
                    @error('payment_cycle')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="start_date" class="form-label">{{ __('customers.fields.start_date') }}</label>
                    <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                           id="start_date" name="start_date"
                           value="{{ old('start_date', $customer?->start_date?->toDateString()) }}">
                    <div class="form-text">{{ __('customers.help.start_date') }}</div>
                    @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body border-bottom">
                <h3 class="h6 mb-0">{{ __('customers.preferences.title') }}</h3>
                <div class="small text-body-secondary">{{ __('customers.preferences.subtitle') }}</div>
            </div>

            <div class="card-body">
                {{--
                    The rule stated where the person setting the numbers will read it.
                    A reminder is a note for whoever enters the day; it is never
                    filled into a quantity field and never creates a delivery.
                --}}
                <div class="alert alert-light border d-flex align-items-start gap-2 small" role="note">
                    <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
                    <div>{{ __('customers.reminders.explanation') }}</div>
                </div>

                @foreach ($milkTypes as $milkType)
                    @php
                        $existing = $customer?->preferences
                            ->firstWhere(fn ($p) => $p->milk_type === $milkType);
                        $field = "preferences.{$milkType->value}";
                        $isActive = (bool) old("{$field}.is_active", $existing?->is_active ?? false);
                    @endphp

                    <div class="border rounded p-3 {{ $loop->last ? '' : 'mb-3' }}">
                        <div class="form-check mb-2">
                            <input type="hidden" name="preferences[{{ $milkType->value }}][is_active]" value="0">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="pref-{{ $milkType->value }}"
                                   name="preferences[{{ $milkType->value }}][is_active]"
                                   @checked($isActive)>
                            <label class="form-check-label fw-medium" for="pref-{{ $milkType->value }}">
                                {{ __('customers.preferences.takes_this_milk', ['type' => $milkType->label()]) }}
                            </label>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label for="pref-{{ $milkType->value }}-morning" class="form-label small mb-1">
                                    {{ __('customers.fields.morning_reminder') }}
                                </label>
                                <input type="number"
                                       class="form-control form-control-sm text-end font-monospace @error("{$field}.morning") is-invalid @enderror"
                                       id="pref-{{ $milkType->value }}-morning"
                                       name="preferences[{{ $milkType->value }}][morning]"
                                       value="{{ old("{$field}.morning", $existing?->morningReminder()) }}"
                                       step="0.001" min="0" inputmode="decimal" placeholder="0.000">
                                @error("{$field}.morning")<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <label for="pref-{{ $milkType->value }}-evening" class="form-label small mb-1">
                                    {{ __('customers.fields.evening_reminder') }}
                                </label>
                                <input type="number"
                                       class="form-control form-control-sm text-end font-monospace @error("{$field}.evening") is-invalid @enderror"
                                       id="pref-{{ $milkType->value }}-evening"
                                       name="preferences[{{ $milkType->value }}][evening]"
                                       value="{{ old("{$field}.evening", $existing?->eveningReminder()) }}"
                                       step="0.001" min="0" inputmode="decimal" placeholder="0.000">
                                @error("{$field}.evening")<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="small text-body-secondary mt-2">{{ __('customers.preferences.inactive_note') }}</div>
            </div>
        </div>
    </div>
</div>
