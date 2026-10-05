@extends('layouts.app')

@section('title', __('settings.farm.create_title'))

@section('header')
    <x-page-header :title="__('settings.farm.create_title')" :back="route('settings.farms.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('settings.farms.store') }}" novalidate>
                @csrf

                @include('settings.farms._form', ['farm' => null])

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.create') }}</button>
                    <a href="{{ route('settings.farms.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
