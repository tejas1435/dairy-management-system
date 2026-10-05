{{--
    The account history, one transaction per line, with a running balance.

    Deliberately not the customer statement's shape. A customer's day is grouped into
    morning and evening because that is their round; a Mandali's collections are shown
    individually with the fat, SNF and agreed rate, because those are the figures that
    will be compared against the dairy's own statement.

    The opening balance is what was owed the day before the period, so a filtered page
    still reconciles with the one before it.

    Shared by the trade profile and the period statement. The statement heads its own
    page and carries its own period controls and summary, so those three parts are
    optional here rather than reimplemented there: one table means one place for the
    row rendering to be right.
--}}
@php
    $ledgerTitle = $ledgerTitle ?? __('buyers.ledger.title');
    $showFilter = $showFilter ?? true;
    $showTotals = $showTotals ?? true;
@endphp

<div class="card shadow-sm mb-3">
    <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-end gap-2">
        <div>
            <h2 class="h6 mb-0">{{ $ledgerTitle }}</h2>
            <div class="small text-body-secondary">
                {{ \Illuminate\Support\Carbon::parse($from)->translatedFormat('d-m-Y') }}
                —
                {{ \Illuminate\Support\Carbon::parse($to)->translatedFormat('d-m-Y') }}
            </div>
        </div>

        @if ($showFilter)
            <form method="GET" action="{{ route($routePrefix.'.show', $buyer) }}" class="row g-2 align-items-end">
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
        @endif
    </div>

    @if ($showTotals)
        <div class="card-body py-2 border-bottom d-flex flex-wrap gap-3 small">
            <span>
                <span class="text-body-secondary">{{ __('buyers.ledger.milk') }}</span>
                <x-litres :quantity="$statement['totals']['milk']" class="ms-1" />
            </span>
            <span>
                <span class="text-body-secondary">{{ __('buyers.ledger.sales') }}</span>
                <x-money :amount="$statement['totals']['sales']" class="ms-1" />
            </span>
            <span>
                <span class="text-body-secondary">{{ __('buyers.ledger.adjustments') }}</span>
                <x-money :amount="$statement['totals']['adjustments']" signed class="ms-1" />
            </span>
            <span>
                <span class="text-body-secondary">{{ __('buyers.ledger.payments') }}</span>
                <x-money :amount="$statement['totals']['payments']" class="ms-1" />
            </span>
        </div>
    @endif

    {{--
        The table is drawn even for a period with no transactions in it, because the
        opening and closing balances are themselves the answer: a quiet month on an
        account that is still owed money must not read as an empty account.
    --}}
    <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ $ledgerTitle }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('buyers.sale.fields.date') }}</th>
                        <th scope="col">{{ __('buyers.sale.fields.source') }}</th>
                        <th scope="col">{{ __('buyers.sale.fields.milk_type') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.sale.fields.quantity') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.sale.fields.fat') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.sale.fields.snf') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.sale.fields.rate') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.sale.fields.amount') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.ledger.balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-light">
                        <td colspan="8" class="small text-body-secondary">{{ __('buyers.ledger.opening') }}</td>
                        <td class="text-end"><x-money :amount="$statement['opening']" muted /></td>
                    </tr>

                    @if ($statement['rows']->isEmpty())
                        <tr>
                            <td colspan="9" class="text-center text-body-secondary py-3">
                                {{ __('buyers.ledger.empty') }}
                            </td>
                        </tr>
                    @endif

                    @foreach ($statement['rows'] as $row)
                        <tr>
                            <td class="text-nowrap">{{ $row->date->translatedFormat('d-m-Y') }}</td>

                            @if ($row->kind === 'sale')
                                <td class="small">
                                    {{ $row->reference }}
                                    <span class="text-body-secondary">· {{ $row->shift->label() }}</span>
                                </td>
                                <td>{{ $row->milkType->label() }}</td>
                                <td class="text-end"><x-litres :quantity="$row->quantity" :unit="false" /></td>
                                <td class="text-end">{{ $row->fat !== null ? number_format((float) $row->fat, 2) : '—' }}</td>
                                <td class="text-end">{{ $row->snf !== null ? number_format((float) $row->snf, 2) : '—' }}</td>
                                <td class="text-end"><x-money :amount="$row->rate" muted /></td>
                                <td class="text-end"><x-money :amount="$row->amount" /></td>
                            @elseif ($row->kind === 'adjustment')
                                <td class="small" colspan="2">
                                    <span class="badge rounded-pill {{ $row->direction->badge() }}">
                                        {{ __('buyers.adjustments.title') }} · {{ $row->direction->label() }}
                                    </span>
                                    <div class="text-body-secondary">{{ $row->reference }}</div>
                                </td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end"><x-money :amount="$row->balanceEffect" signed /></td>
                            @else
                                <td class="small" colspan="2">
                                    <span class="badge rounded-pill text-success-emphasis bg-success-subtle border border-success-subtle">
                                        {{ __('buyers.payment.title') }}
                                    </span>
                                    <div class="text-body-secondary">
                                        {{ $row->payment->paymentMethod?->name }}
                                        @if ($row->payment->settlement)
                                            · {{ __('buyers.payment.against_settlement') }}
                                        @endif
                                    </div>
                                </td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end"><x-money :amount="$row->balanceEffect" signed /></td>
                            @endif

                            <td class="text-end"><x-money :amount="$row->balance" class="fw-semibold" /></td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot class="table-light">
                    <tr>
                        <th scope="row" colspan="8">{{ __('buyers.ledger.closing') }}</th>
                        <td class="text-end"><x-money :amount="$statement['closing']" /></td>
                    </tr>
                </tfoot>
            </table>
    </div>
</div>
