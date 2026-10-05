@extends('layouts.app')

@section('title', __('settings.farm.edit_title'))

@section('header')
    <x-page-header :title="__('settings.farm.edit_title')" :subtitle="$farm->label()"
                   :back="route('settings.farms.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('settings.farms.update', $farm) }}" novalidate>
                @csrf
                @method('PUT')

                @include('settings.farms._form')

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save_changes') }}</button>
                    <a href="{{ route('settings.farms.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
