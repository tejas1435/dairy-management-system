<div class="card shadow-sm mb-3">
    <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h3 class="h6 mb-0">{{ __('customers.statement.title') }}</h3>
            <div class="small text-body-secondary">{{ __('customers.ledger.subtitle') }}</div>
        </div>

        <form method="GET" action="{{ route('customers.show', $customer) }}"
              class="d-flex flex-wrap align-items-end gap-2">
            <div>
                <label for="from" class="form-label small mb-1">{{ __('customers.statement.period') }}</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from" value="{{ $from }}">
            </div>
            <div>
                <label for="to" class="form-label small mb-1">&rarr;</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to" value="{{ $to }}">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary">
                {{ __('customers.statement.apply') }}
            </button>
        </form>
    </div>

    {{--
        Four figures, kept apart on purpose. Sales are what was delivered and billed;
        payments are what actually arrived. Showing one number for both is the mistake
        the outstanding balance exists to expose.
    --}}
    <div class="card-body border-bottom">
        <div class="row g-3 text-center">
            <div class="col-6 col-lg-3">
                <div class="small text-body-secondary">{{ __('customers.statement.milk_quantity') }}</div>
                <div class="fs-5"><x-litres :quantity="$statement['totals']['milk']" strong /></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small text-body-secondary">{{ __('customers.statement.sales') }}</div>
                <div class="fs-5"><x-money :amount="$statement['totals']['sales']" class="fw-semibold" /></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small text-body-secondary">{{ __('customers.statement.payments_received') }}</div>
                <div class="fs-5"><x-money :amount="$statement['totals']['payments']" class="fw-semibold" /></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small text-body-secondary">{{ __('customers.statement.outstanding_total') }}</div>
                <div class="fs-5"><x-money :amount="$outstanding['outstanding']" class="fw-semibold" /></div>
            </div>
        </div>

        <div class="small text-body-secondary mt-3">{{ __('customers.statement.not_cash') }}</div>
    </div>

    @if ($statement['rows']->isEmpty())
        <div class="card-body">
            <x-empty-state icon="journal-text" :title="__('customers.ledger.none')"
                           :description="__('customers.ledger.subtitle')" />
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ __('customers.ledger.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('customers.fields.date') }}</th>
                        <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.morning') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.evening') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.total_milk') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.rate') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.sale_amount') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.payment') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.ledger.balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Opening balance, so a filtered period still reads correctly. --}}
                    <tr class="table-light">
                        <th scope="row" colspan="8" class="fw-normal small">
                            {{ __('customers.ledger.opening_balance') }}
                        </th>
                        <td class="text-end"><x-money :amount="$statement['opening']" muted /></td>
                    </tr>

                    @foreach ($statement['rows'] as $row)
                        <tr>
                            <td class="small text-nowrap">{{ $row->date->translatedFormat('d-m-Y') }}</td>
                            <td class="small">{{ $row->milkType?->label() ?? '—' }}</td>
                            <td class="text-end"><x-litres :quantity="$row->morningQuantity" :unit="false" muted /></td>
                            <td class="text-end"><x-litres :quantity="$row->eveningQuantity" :unit="false" muted /></td>
                            <td class="text-end"><x-litres :quantity="$row->totalQuantity" :unit="false" /></td>
                            <td class="text-end small font-monospace">
                                @if ($row->kind === 'payment')
                                    —
                                @elseif ($row->rate !== null)
                                    &#8377;{{ number_format((float) $row->rate, 2) }}
                                @else
                                    <span class="text-body-secondary">{{ __('customers.ledger.mixed_rate') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($row->amount !== null)
                                    <x-money :amount="$row->amount" />
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($row->payment !== null)
                                    <x-money :amount="$row->payment" class="text-success-emphasis" />
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end"><x-money :amount="$row->balance" /></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th scope="row" colspan="8">{{ __('customers.ledger.closing_balance') }}</th>
                        <td class="text-end"><x-money :amount="$statement['closing']" class="fw-semibold" /></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
