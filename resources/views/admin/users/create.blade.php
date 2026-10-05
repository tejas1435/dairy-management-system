@extends('layouts.app')

@section('title', __('users.create_title'))

@section('header')
    <x-page-header :title="__('users.create_title')" :back="route('admin.users.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.store') }}" novalidate>
                @csrf

                @include('admin.users._form', ['user' => null])

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.create') }}</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
