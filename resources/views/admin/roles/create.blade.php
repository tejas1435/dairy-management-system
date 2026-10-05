@extends('layouts.app')

@section('title', __('roles.create_title'))

@section('header')
    <x-page-header :title="__('roles.create_title')" :back="route('admin.roles.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.roles.store') }}" novalidate>
                @csrf

                @include('admin.roles._form', ['role' => null])

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.create') }}</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
