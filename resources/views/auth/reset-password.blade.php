@extends('layouts.guest')

@section('title', __('auth.reset.title'))
@section('heading', __('auth.reset.title'))

@section('content')
    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('auth.login.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autocomplete="username" inputmode="email">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('auth.reset.password') }}</label>
            <input type="password" id="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autofocus autocomplete="new-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">{{ __('auth.reset.confirm') }}</label>
            <input type="password" id="password_confirmation" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-100">
            {{ __('auth.reset.submit') }}
        </button>
    </form>
@endsection
