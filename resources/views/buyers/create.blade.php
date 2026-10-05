@extends('layouts.app')

@section('title', __('buyers.create_title'))

@section('header')
    <x-page-header :title="__('buyers.create_title')" :back="route('buyers.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('buyers.store') }}" novalidate>
                @csrf
                @include('buyers._form', ['buyer' => null])
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.create') }}</button>
                    <a href="{{ route('buyers.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
