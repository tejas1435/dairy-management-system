{{--
    The Mandali's settlement history, newest first.

    The payment columns are derived, not stored: what has been received is the sum of
    active receipts linked to each settlement, which is why a withdrawn receipt moves
    a status back on its own.
--}}
<div class="card shadow-sm mb-3">
    <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">{{ __('buyers.settlement.title') }}</h2>
        @can('manageSettlement', $buyer)
            <a href="{{ route('mandalis.settlements.create', $buyer) }}" class="btn btn-sm btn-outline-primary">
                {{ __('buyers.settlement.create') }}
            </a>
        @endcan
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden">{{ __('buyers.settlement.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('buyers.settlement.fields.period_start') }}</th>
                    <th scope="col" class="text-end">{{ __('buyers.settlement.fields.milk_quantity') }}</th>
                    <th scope="col" class="text-end">{{ __('buyers.settlement.fields.expected_amount') }}</th>
                    <th scope="col" class="text-end">{{ __('buyers.settlement.fields.statement_amount') }}</th>
                    <th scope="col" class="text-end">{{ __('buyers.settlement.fields.difference') }}</th>
                    <th scope="col" class="text-end">{{ __('buyers.settlement.fields.paid') }}</th>
                    <th scope="col">{{ __('app.labels.status') }}</th>
                    <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($settlements as $settlement)
                    <tr>
                        <td class="text-nowrap">{{ $settlement->periodLabel() }}</td>
                        <td class="text-end"><x-litres :quantity="$settlement->milk_quantity" :unit="false" /></td>
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
                                <x-money :amount="$settlement->paidAmount()" />
                                <div class="small text-body-secondary">
                                    {{ __('buyers.settlement.fields.remaining') }}
                                    <x-money :amount="$settlement->remainingAmount()" muted />
                                </div>
                            @else
                                <span class="text-body-secondary">&mdash;</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge rounded-pill {{ $settlement->status->badge() }}">
                                {{ $settlement->status->label() }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('manageSettlement', $buyer)
                                <a href="{{ route('mandalis.settlements.show', [$buyer, $settlement]) }}"
                                   class="btn btn-sm btn-outline-secondary">{{ __('buyers.settlement.singular') }}</a>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
