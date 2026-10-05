@extends('layouts.app')

@section('title', $buyer->name)

@section('header')
    <x-page-header :title="$buyer->name"
                   :subtitle="__($translationPrefix.'.singular').($buyer->area ? ' · '.$buyer->area : '')"
                   :back="route($routePrefix.'.index')">
        @can('update', $buyer)
            <a href="{{ route('buyers.edit', $buyer) }}" class="btn btn-outline-secondary">
                {{ __('app.actions.edit') }}
            </a>
        @endcan
        {{-- Same authorisation as this page, since it is the same data read differently. --}}
        <a href="{{ route($routePrefix.'.statement', [$buyer, 'from' => $from, 'to' => $to]) }}"
           class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>{{ __('buyers.statement.open') }}
        </a>
        {{--
            The next thing somebody on this page usually wants: record what this buyer
            took today. The form opens on its own workflow, which is where the fat
            fields and the rate rules live.
        --}}
        @can('milk.sale.create')
            <a href="{{ route($saleRoute, ['buyer' => $buyer->id]) }}" class="btn btn-outline-primary">
                <i class="bi bi-droplet-half me-1" aria-hidden="true"></i>{{ __('buyers.sale.record') }}
            </a>
        @endcan
        @if ($buyer->isMandali())
            @can('manageSettlement', $buyer)
                <a href="{{ route('mandalis.settlements.index', $buyer) }}" class="btn btn-outline-primary">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>{{ __('buyers.actions.view_settlements') }}
                </a>
            @endcan
        @endif
    </x-page-header>
@endsection

@section('content')
    <x-alerts />
    <x-validation-errors />

    {{--
        The outstanding breakdown, in the specification's own terms. The adjustments
        term is real as of Phase 5; it was a named zero before, and the screen did not
        change when it was filled in.
    --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.sales') }}</div>
                    <div class="fs-5"><x-money :amount="$breakdown['sales']" /></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.adjustments') }}</div>
                    <div class="fs-5"><x-money :amount="$breakdown['adjustments']" signed /></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.payments') }}</div>
                    <div class="fs-5"><x-money :amount="$breakdown['payments']" /></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm h-100 border-primary-subtle">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.outstanding') }}</div>
                    <div class="fs-5 fw-semibold"><x-money :amount="$breakdown['outstanding']" /></div>
                </div>
            </div>
        </div>
    </div>

    <p class="small text-body-secondary">{{ __('buyers.ledger.formula') }}</p>

    @include('buyers.channel._ledger', [
        'buyer' => $buyer,
        'statement' => $statement,
        'from' => $from,
        'to' => $to,
        'routePrefix' => $routePrefix,
    ])

    @if ($buyer->isMandali() && $settlements->isNotEmpty())
        @include('buyers.channel._settlements', ['buyer' => $buyer, 'settlements' => $settlements])
    @endif

    {{--
        `payable` is the settlements a receipt may be attached to, filtered in the
        controller from the settlements already loaded above rather than queried again
        in the partial.
    --}}
    @include('buyers.channel._payments', [
        'buyer' => $buyer,
        'payments' => $payments,
        'accounts' => $accounts,
        'paymentMethods' => $paymentMethods,
        'breakdown' => $breakdown,
        'payable' => $payableSettlements,
    ])

    @if ($adjustments->isNotEmpty())
        @include('buyers.channel._adjustments', ['adjustments' => $adjustments])
    @endif
@endsection
