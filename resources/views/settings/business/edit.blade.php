@extends('layouts.app')

@section('title', __('settings.business.title'))

@section('header')
    <x-page-header :title="__('settings.business.title')" :subtitle="__('settings.business.subtitle')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('settings.business.update') }}" novalidate>
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-12 col-xl-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">{{ __('settings.business.contact_heading') }}</h3>

                        <div class="mb-3">
                            <label for="name" class="form-label">{{ __('settings.business.fields.name') }} *</label>
                            <input type="text" id="name" name="name" value="{{ old('name', $business->name) }}"
                                   class="form-control @error('name') is-invalid @enderror" required autofocus>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="legal_name" class="form-label">
                                {{ __('settings.business.fields.legal_name') }}
                                <span class="text-body-secondary small">({{ __('app.labels.optional') }})</span>
                            </label>
                            <input type="text" id="legal_name" name="legal_name"
                                   value="{{ old('legal_name', $business->legal_name) }}"
                                   class="form-control @error('legal_name') is-invalid @enderror">
                            @error('legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6">
                                <label for="mobile" class="form-label">{{ __('settings.business.fields.mobile') }}</label>
                                <input type="tel" id="mobile" name="mobile" value="{{ old('mobile', $business->mobile) }}"
                                       class="form-control @error('mobile') is-invalid @enderror" inputmode="tel">
                                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="email" class="form-label">{{ __('settings.business.fields.email') }}</label>
                                <input type="email" id="email" name="email" value="{{ old('email', $business->email) }}"
                                       class="form-control @error('email') is-invalid @enderror" inputmode="email">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label">{{ __('settings.business.fields.address') }}</label>
                            <textarea id="address" name="address" rows="3"
                                      class="form-control @error('address') is-invalid @enderror">{{ old('address', $business->address) }}</textarea>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                                   @checked(old('is_active', $business->is_active))>
                            <label for="is_active" class="form-check-label">
                                {{ __('settings.business.fields.is_active') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">{{ __('settings.business.regional_heading') }}</h3>

                        <div class="mb-3">
                            <label for="currency" class="form-label">{{ __('settings.business.fields.currency') }} *</label>
                            <select id="currency" name="currency"
                                    class="form-select @error('currency') is-invalid @enderror" required>
                                <option value="INR" @selected(old('currency', $business->currency) === 'INR')>
                                    INR (&#8377;)
                                </option>
                            </select>
                            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('settings.business.help.currency') }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="timezone" class="form-label">{{ __('settings.business.fields.timezone') }} *</label>
                            <select id="timezone" name="timezone"
                                    class="form-select @error('timezone') is-invalid @enderror" required>
                                @foreach ($timezones as $timezone)
                                    <option value="{{ $timezone }}"
                                        @selected(old('timezone', $business->timezone) === $timezone)>
                                        {{ $timezone }}
                                    </option>
                                @endforeach
                            </select>
                            @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="date_format" class="form-label">
                                {{ __('settings.business.fields.date_format') }} *
                            </label>
                            <select id="date_format" name="date_format"
                                    class="form-select @error('date_format') is-invalid @enderror" required>
                                @foreach ($dateFormats as $format)
                                    <option value="{{ $format->value }}"
                                        @selected(old('date_format', $business->date_format->value) === $format->value)>
                                        {{ $format->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('date_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-0">
                            <label for="default_locale" class="form-label">
                                {{ __('settings.business.fields.default_locale') }} *
                            </label>
                            <select id="default_locale" name="default_locale"
                                    class="form-select @error('default_locale') is-invalid @enderror" required>
                                @foreach ($locales as $locale)
                                    <option value="{{ $locale->value }}"
                                        @selected(old('default_locale', $business->default_locale->value) === $locale->value)>
                                        {{ $locale->nativeName() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('default_locale')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('settings.business.help.default_locale') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save_changes') }}</button>
        </div>
    </form>
@endsection
