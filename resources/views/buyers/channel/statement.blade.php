@extends('layouts.app')

@section('title', __('buyers.statement.title').' · '.$buyer->name)

@section('header')
    <x-page-header :title="__('buyers.statement.title')"
                   :subtitle="$buyer->name.' · '.$month->translatedFormat('F Y')"
                   :back="route($routePrefix.'.show', $buyer)" />
@endsection

@section('content')
    {{--
        The period report MASTER_SPEC section 23 asks for, on screen. Deliberately a
        *view* over canonical records: every figure comes from the same
        `BuyerTradeLedger` and `BuyerOutstandingService` the profile uses, and nothing
        here is computed in Blade. There is no report-only balance table, because a
        second place to store a total is a second place for it to be wrong.
    --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="btn-group" role="group" aria-label="{{ __('buyers.statement.period') }}">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route($routePrefix.'.statement', [$buyer, 'month' => $previousMonth]) }}">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('buyers.statement.previous_month') }}</span>
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route($routePrefix.'.statement', [$buyer, 'month' => $thisMonth]) }}">
                {{ __('buyers.statement.this_month') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route($routePrefix.'.statement', [$buyer, 'month' => $nextMonth]) }}">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('buyers.statement.next_month') }}</span>
            </a>
        </div>

        <form method="GET" action="{{ route($routePrefix.'.statement', $buyer) }}"
              class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="from" class="form-label small mb-1">{{ __('buyers.ledger.from') }}</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from" value="{{ $from }}">
            </div>
            <div class="col-auto">
                <label for="to" class="form-label small mb-1">{{ __('buyers.ledger.to') }}</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to" value="{{ $to }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('app.actions.filter') }}</button>
            </div>
        </form>
    </div>

    {{-- The five summary figures the specification lists, for the period. --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body border-bottom">
            <h2 class="h6 mb-0">{{ __('buyers.statement.summary') }}</h2>
            <div class="small text-body-secondary">
                {{ \Illuminate\Support\Carbon::parse($from)->translatedFormat('d-m-Y') }}
                —
                {{ \Illuminate\Support\Carbon::parse($to)->translatedFormat('d-m-Y') }}
            </div>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-2">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.milk') }}</div>
                    <div class="fs-6"><x-litres :quantity="$statement['totals']['milk']" /></div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="small text-body-secondary">{{ __('buyers.statement.expected_sales') }}</div>
                    <div class="fs-6"><x-money :amount="$statement['totals']['sales']" /></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.adjustments') }}</div>
                    <div class="fs-6"><x-money :amount="$statement['totals']['adjustments']" signed /></div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="small text-body-secondary">{{ __('buyers.ledger.payments') }}</div>
                    <div class="fs-6"><x-money :amount="$statement['totals']['payments']" /></div>
                </div>
                <div class="col-12 col-md-3">
                    {{--
                        The whole balance, not the period's movement. A statement that
                        showed only the month would invite "so we owe nothing" after a
                        month that happened to be paid in full.
                    --}}
                    <div class="small text-body-secondary">{{ __('buyers.ledger.outstanding') }}</div>
                    <div class="fs-6 fw-semibold"><x-money :amount="$breakdown['outstanding']" /></div>
                </div>
            </div>

            <p class="small text-body-secondary mb-0 mt-2">{{ __('buyers.ledger.formula') }}</p>
        </div>
    </div>

    @if ($settlements->isNotEmpty())
        <div class="card shadow-sm mb-3">
            <div class="card-body border-bottom">
                <h2 class="h6 mb-0">{{ __('buyers.statement.settlements_in_period') }}</h2>
                <p class="small text-body-secondary mb-0">{{ __('buyers.statement.settlements_help') }}</p>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">{{ __('buyers.statement.settlements_in_period') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('buyers.settlement.fields.period_start') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.settlement.fields.expected_amount') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.settlement.fields.statement_amount') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.settlement.fields.difference') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.settlement.fields.amount_due') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.settlement.fields.remaining') }}</th>
                            <th scope="col">{{ __('app.labels.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($settlements as $settlement)
                            <tr>
                                <td class="text-nowrap">
                                    <a href="{{ route('mandalis.settlements.show', [$buyer, $settlement]) }}">
                                        {{ $settlement->periodLabel() }}
                                    </a>
                                </td>
                                <td class="text-end">
                                    @if ($settlement->expected_amount !== null)
                                        <x-money :amount="$settlement->expected_amount" />
                                    @else
                                        <span class="text-body-secondary">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($settlement->hasStatement())
                                        <x-money :amount="$settlement->statement_amount" />
                                    @else
                                        <span class="text-body-secondary">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($settlement->difference !== null)
                                        <x-money :amount="$settlement->difference" signed />
                                    @else
                                        <span class="text-body-secondary">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($settlement->isFinalized())
                                        <x-money :amount="$settlement->amountDue()" />
                                    @else
                                        <span class="text-body-secondary">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($settlement->isFinalized())
                                        <x-money :amount="$settlement->remainingAmount()" />
                                    @else
                                        <span class="text-body-secondary">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge rounded-pill {{ $settlement->status->badge() }}">
                                        {{ $settlement->status->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{--
        The same ledger table the profile draws, headed as the statement's transaction
        detail: date, shift, milk type, quantity, fat, SNF, the rate snapshot, the sale
        amount, settlement adjustments, receipts and a running balance. Its own period
        controls and totals are suppressed because this page already carries both.
    --}}
    @include('buyers.channel._ledger', [
        'ledgerTitle' => __('buyers.statement.transactions'),
        'showFilter' => false,
        'showTotals' => false,
    ])
@endsection
