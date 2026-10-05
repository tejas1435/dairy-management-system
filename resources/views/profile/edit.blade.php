@extends('layouts.app')

@section('title', __('profile.title'))

@section('header')
    <x-page-header :title="__('profile.title')" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6 mb-1">{{ __('profile.details.heading') }}</h3>
                    <p class="text-body-secondary small mb-3">{{ __('profile.details.description') }}</p>

                    <form method="POST" action="{{ route('profile.update') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">{{ __('profile.fields.name') }} *</label>
                            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">{{ __('profile.fields.email') }} *</label>
                            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                                   class="form-control @error('email') is-invalid @enderror" required
                                   autocomplete="username">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label for="locale" class="form-label">{{ __('profile.fields.locale') }} *</label>
                            <select id="locale" name="locale"
                                    class="form-select @error('locale') is-invalid @enderror" required>
                                @foreach ($locales as $locale)
                                    <option value="{{ $locale->value }}"
                                        @selected(old('locale', $user->locale->value) === $locale->value)>
                                        {{ $locale->nativeName() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('locale')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ __('app.actions.save_changes') }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-1">{{ __('profile.password.heading') }}</h3>
                    <p class="text-body-secondary small mb-3">{{ __('profile.password.description') }}</p>

                    <form method="POST" action="{{ route('profile.password.update') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">
                                {{ __('profile.fields.current_password') }} *
                            </label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control @error('current_password') is-invalid @enderror"
                                   required autocomplete="current-password">
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">{{ __('profile.fields.new_password') }} *</label>
                            <input type="password" id="password" name="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   required autocomplete="new-password">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label for="password_confirmation" class="form-label">
                                {{ __('profile.fields.confirm_password') }} *
                            </label>
                            <input type="password" id="password_confirmation" name="password_confirmation"
                                   class="form-control" required autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ __('profile.password.submit') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-1">{{ __('profile.roles.heading') }}</h3>
                    {{--
                        Read-only on purpose. A user cannot change their own roles
                        or active status; the profile request never reads those
                        fields.
                    --}}
                    <p class="text-body-secondary small mb-3">{{ __('profile.roles.description') }}</p>

                    @forelse ($user->roles as $role)
                        <span class="badge text-primary-emphasis bg-primary-subtle border border-primary-subtle me-1 mb-1">
                            {{ $role->name }}
                        </span>
                    @empty
                        <p class="text-body-secondary small mb-0">{{ __('dashboard.account.no_roles') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
