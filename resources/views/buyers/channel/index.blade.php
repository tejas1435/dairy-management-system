@extends('layouts.app')

@section('title', __($translationPrefix.'.title'))

@section('header')
    <x-page-header :title="__($translationPrefix.'.title')" :subtitle="__($translationPrefix.'.subtitle')">
        @if ($channel)
            @can('create', App\Models\Buyer::class)
                {{--
                    Links to the Phase 2 buyer form with this channel pre-selected
                    rather than carrying a second copy of it. The posted channel is
                    still authorised on its own terms.
                --}}
                <a href="{{ route('buyers.create', ['channel' => $channel->id]) }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __($translationPrefix.'.create') }}
                </a>
            @endcan
        @endif
    </x-page-header>
@endsection

@section('content')
    <x-alerts />

    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route($routePrefix.'.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label for="search" class="form-label small mb-1">{{ __('buyers.filters.search') }}</label>
                    <input type="search" class="form-control form-control-sm" id="search" name="search"
                           value="{{ $filters['search'] ?? '' }}" autocomplete="off">
                </div>
                <div class="col-6 col-md-3">
                    <label for="status" class="form-label small mb-1">{{ __('buyers.columns.status') }}</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">{{ __('app.status.all') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('app.status.active') }}</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('app.status.inactive') }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">{{ __('app.actions.filter') }}</button>
                </div>
            </form>
        </div>

        @if ($buyers->isEmpty())
            <x-empty-state icon="shop"
                           :title="__($translationPrefix.'.empty')"
                           :description="__($translationPrefix.'.empty_help')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">{{ __($translationPrefix.'.title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('buyers.columns.name') }}</th>
                            {{--
                                Only when this screen serves more than one channel,
                                which is the other-buyers list: a hotel and a sweet
                                shop share it, and the channel is the one thing that
                                tells them apart.
                            --}}
                            @unless ($channel)
                                <th scope="col">{{ __('buyers.columns.channel') }}</th>
                            @endunless
                            <th scope="col">{{ __('buyers.columns.mobile') }}</th>
                            <th scope="col">{{ __('buyers.columns.area') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.ledger.outstanding') }}</th>
                            <th scope="col">{{ __('buyers.columns.status') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buyers as $buyer)
                            <tr>
                                <th scope="row" class="fw-normal">
                                    <a href="{{ route($routePrefix.'.show', $buyer) }}" class="fw-medium">{{ $buyer->name }}</a>
                                </th>
                                @unless ($channel)
                                    <td>{{ $buyer->salesChannel?->name ?: '—' }}</td>
                                @endunless
                                <td>{{ $buyer->mobile ?: '—' }}</td>
                                <td>{{ $buyer->area ?: '—' }}</td>
                                <td class="text-end">
                                    <x-money :amount="$outstanding[$buyer->id] ?? '0.00'" />
                                </td>
                                <td>
                                    <x-status-badge :active="(bool) $buyer->is_active" />
                                </td>
                                <td class="text-end">
                                    <a href="{{ route($routePrefix.'.show', $buyer) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        {{ __('buyers.actions.view_ledger') }}
                                    </a>
                                    @can('update', $buyer)
                                        <a href="{{ route('buyers.edit', $buyer) }}"
                                           class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-body border-top">
                {{ $buyers->links() }}
            </div>
        @endif
    </div>
@endsection
