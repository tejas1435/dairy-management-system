@extends('layouts.guest')

@section('title', __('auth.login.title'))
@section('heading', __('auth.login.title'))
@section('subheading', __('auth.login.subtitle'))

@section('content')
    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('auth.login.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username" inputmode="email">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('auth.login.password') }}</label>
            <input type="password" id="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-check mb-4">
            <input type="checkbox" id="remember" name="remember" value="1" class="form-check-input"
                   @checked(old('remember'))>
            <label for="remember" class="form-check-label">{{ __('auth.login.remember') }}</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            {{ __('auth.login.submit') }}
        </button>
    </form>

    <div class="text-center mt-3">
        <a href="{{ route('password.request') }}" class="small">{{ __('auth.login.forgot') }}</a>
    </div>
@endsection

@section('below-card')
    {{-- There is no registration link because there is no registration route. --}}
    {{ __('auth.login.no_registration') }}
@endsection
