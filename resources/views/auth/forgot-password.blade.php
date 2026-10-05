@extends('layouts.guest')

@section('title', __('auth.forgot.title'))
@section('heading', __('auth.forgot.title'))
@section('subheading', __('auth.forgot.subtitle'))

@section('content')
    <form method="POST" action="{{ route('password.email') }}" novalidate>
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

        <button type="submit" class="btn btn-primary w-100">
            {{ __('auth.forgot.submit') }}
        </button>
    </form>

    <div class="text-center mt-3">
        <a href="{{ route('login') }}" class="small">{{ __('auth.forgot.back_to_login') }}</a>
    </div>
@endsection
