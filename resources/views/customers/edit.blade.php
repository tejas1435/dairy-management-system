@extends('layouts.app')

@section('title', $customer->name)

@section('header')
    <x-page-header :title="__('customers.actions.edit_profile')" :subtitle="$customer->name"
                   :back="route('customers.show', $customer)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('customers.update', $customer) }}">
        @csrf
        @method('PUT')

        @include('customers._form', ['customer' => $customer])

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>{{ __('app.actions.save_changes') }}
            </button>
            <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary">
                {{ __('app.actions.cancel') }}
            </a>
        </div>
    </form>

    @can('archive', $customer)
        <div class="card shadow-sm mt-3 border-warning-subtle">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="h6 mb-1">
                        {{ $customer->is_active ? __('customers.actions.archive') : __('customers.actions.restore') }}
                    </h3>
                    <div class="small text-body-secondary">{{ __('customers.help.archive') }}</div>
                </div>

                <form method="POST" action="{{ route('customers.status.update', $customer) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="is_active" value="{{ $customer->is_active ? 0 : 1 }}">
                    <button type="submit" class="btn btn-sm {{ $customer->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                        {{ $customer->is_active ? __('customers.actions.archive') : __('customers.actions.restore') }}
                    </button>
                </form>
            </div>
        </div>
    @endcan
@endsection
