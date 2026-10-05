@extends('layouts.app')

@section('title', __('roles.edit_title'))

@section('header')
    <x-page-header :title="__('roles.edit_title')" :subtitle="$role->name"
                   :back="route('admin.roles.index')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.roles.update', $role) }}" novalidate>
                @csrf
                @method('PUT')

                @include('admin.roles._form')

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('app.actions.save_changes') }}</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('app.actions.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
