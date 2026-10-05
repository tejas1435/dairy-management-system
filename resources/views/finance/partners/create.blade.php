@extends('layouts.app')

@section('title', __('partners.create_title'))

@section('header')
    <x-page-header :title="__('partners.create_title')" :back="route('finance.partners.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('finance.partners.store') }}" novalidate>
                @csrf
                @include('finance.partners._form', ['partner' => null])
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.create') }}</button>
                    <a href="{{ route('finance.partners.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
