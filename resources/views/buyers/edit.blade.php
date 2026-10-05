@extends('layouts.app')

@section('title', __('buyers.edit_title'))

@section('header')
    <x-page-header :title="__('buyers.edit_title')" :subtitle="$buyer->name" :back="route('buyers.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('buyers.update', $buyer) }}" novalidate>
                @csrf
                @method('PUT')
                @include('buyers._form')
                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save_changes') }}</button>
                    <a href="{{ route('buyers.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
