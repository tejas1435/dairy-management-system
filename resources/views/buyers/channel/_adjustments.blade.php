{{--
    Receivable corrections.

    Each row changes what the buyer owes and nothing else: no litre, no reconciliation
    figure, no account. A cancelled one keeps its amount and reason but stops counting,
    which is why the balance moves back without a second correction being written.
--}}
<div class="card shadow-sm mb-3">
    <div class="card-body border-bottom">
        <h2 class="h6 mb-0">{{ __('buyers.adjustments.title') }}</h2>
        <p class="small text-body-secondary mb-0">{{ __('buyers.adjustments.help') }}</p>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden">{{ __('buyers.adjustments.title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('buyers.sale.fields.date') }}</th>
                    <th scope="col">{{ __('buyers.settlement.fields.status') }}</th>
                    <th scope="col" class="text-end">{{ __('buyers.sale.fields.amount') }}</th>
                    <th scope="col">{{ __('buyers.settlement.singular') }}</th>
                    <th scope="col">{{ __('milk.fields.reason') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($adjustments as $adjustment)
                    <tr @class(['text-body-secondary' => $adjustment->isCancelled()])>
                        <td class="text-nowrap">{{ $adjustment->adjustment_date->translatedFormat('d-m-Y') }}</td>
                        <td>
                            <span class="badge rounded-pill {{ $adjustment->direction->badge() }}">
                                <i class="bi bi-{{ $adjustment->direction->icon() }} me-1" aria-hidden="true"></i>
                                {{ $adjustment->direction->label() }}
                            </span>
                            @if ($adjustment->isCancelled())
                                <span class="badge rounded-pill text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                    {{ __('buyers.settlement_statuses.cancelled') }}
                                </span>
                            @endif
                        </td>
                        <td class="text-end"><x-money :amount="$adjustment->signedAmount()" signed /></td>
                        <td class="small">
                            {{ $adjustment->settlement ? $adjustment->settlement->period_start->format('d-m-Y').' — '.$adjustment->settlement->period_end->format('d-m-Y') : '—' }}
                        </td>
                        <td class="small">{{ $adjustment->reason }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
