@extends('layouts.app')

@section('title', __('customers.actions.add'))

@section('header')
    <x-page-header :title="__('customers.actions.add')" :subtitle="__('customers.subtitle')"
                   :back="route('customers.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('customers.store') }}">
        @csrf

        {{--
            No sales channel field. A direct customer is assigned the seeded
            `direct_customer` channel server-side; posting one would have no effect.
            Moving a buyer between channels is a deliberate act in the buyer master,
            which re-checks the destination channel's permission.
        --}}
        @include('customers._form', ['customer' => null])

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>{{ __('customers.actions.save') }}
            </button>
            <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">
                {{ __('app.actions.cancel') }}
            </a>
        </div>
    </form>
@endsection
