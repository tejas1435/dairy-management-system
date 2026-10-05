@extends('layouts.app')

@section('title', __('finance.accounts.create_title'))

@section('header')
    <x-page-header :title="__('finance.accounts.create_title')" :back="route('finance.accounts.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('finance.accounts.store') }}" novalidate>
                @csrf
                @include('finance.accounts._form', ['account' => null])
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.create') }}</button>
                    <a href="{{ route('finance.accounts.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
