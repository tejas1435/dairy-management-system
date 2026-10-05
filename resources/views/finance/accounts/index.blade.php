@extends('layouts.app')

@section('title', __('finance.accounts.title'))

@section('header')
    <x-page-header :title="__('finance.accounts.title')" :subtitle="__('finance.accounts.subtitle')">
        <a href="{{ route('finance.accounts.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('finance.accounts.create_title') }}
        </a>
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        @if ($accounts->isEmpty())
            <div class="card-body">
                <x-empty-state icon="wallet2" :description="__('finance.accounts.empty')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('finance.accounts.columns.name') }}</th>
                            <th scope="col">{{ __('finance.accounts.columns.type') }}</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">{{ __('finance.accounts.columns.opening_balance') }}</th>
                            <th scope="col" class="text-end">{{ __('finance.accounts.columns.balance') }}</th>
                            <th scope="col">{{ __('finance.accounts.columns.status') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <td>
                                    <a href="{{ route('finance.accounts.show', $account) }}" class="fw-medium">{{ $account->name }}</a>
                                </td>
                                <td class="small">
                                    <i class="bi bi-{{ $account->type->icon() }} me-1" aria-hidden="true"></i>{{ $account->type->label() }}
                                </td>
                                <td class="text-end d-none d-md-table-cell"><x-money :amount="$account->opening_balance" muted /></td>
                                <td class="text-end fw-medium"><x-money :amount="$account->loadedBalance()" /></td>
                                <td><x-status-badge :active="$account->is_active" /></td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('finance.accounts.edit', $account) }}" class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>
                                    <form method="POST" action="{{ route('finance.accounts.status.update', $account) }}" class="d-inline">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="is_active" value="{{ $account->is_active ? 0 : 1 }}">
                                        <button type="submit" class="btn btn-sm {{ $account->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                            {{ $account->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($accounts->hasPages())
                <div class="card-body border-top">{{ $accounts->links() }}</div>
            @endif
        @endif
    </div>

    <div class="small text-body-secondary mt-3">
        <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('finance.accounts.help.derived_balance') }}</p>
        <p class="mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('finance.accounts.help.no_delete') }}</p>
    </div>
@endsection
