@extends('layouts.app')

@section('title', __('partners.edit_title'))

@section('header')
    <x-page-header :title="__('partners.edit_title')" :subtitle="$partner->name"
                   :back="route('finance.partners.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('finance.partners.update', $partner) }}" novalidate>
                @csrf
                @method('PUT')
                @include('finance.partners._form')
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save_changes') }}</button>
                    <a href="{{ route('finance.partners.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
