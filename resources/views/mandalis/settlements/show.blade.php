@extends('layouts.app')

@section('title', __('buyers.settlement.singular').' · '.$settlement->periodLabel())

@section('header')
    <x-page-header :title="__('buyers.settlement.singular').' — '.$settlement->periodLabel()"
                   :subtitle="$mandali->name"
                   :back="route('mandalis.settlements.index', $mandali)">
        <span class="badge rounded-pill {{ $settlement->status->badge() }} fs-6">
            {{ $settlement->status->label() }}
        </span>
    </x-page-header>
@endsection

@section('content')
    <x-alerts />
    <x-validation-errors />

    {{--
        A draft shows live figures and a finalized settlement shows its own snapshots.
        That distinction is the heart of the screen: once agreed, the numbers stop
        following the deliveries, because an agreed figure that silently moves is worse
        than one that is out of date.
    --}}
    <div class="alert {{ $settlement->isDraft() ? 'alert-secondary' : 'alert-primary' }} py-2 small" role="note">
        @if ($settlement->isDraft())
            {{ __('buyers.settlement.draft_help') }}
        @elseif ($settlement->isCancelled())
            {{ __('buyers.errors.settlement_cancelled') }} {{ $settlement->cancellation_reason }}
        @else
            {{ __('buyers.settlement.finalized_help', ['date' => $settlement->finalized_at?->translatedFormat('d-m-Y') ?? '']) }}
        @endif
    </div>

    @php $figures = $live ?? ['milk_quantity' => $settlement->milk_quantity, 'expected_amount' => $settlement->expected_amount]; @endphp

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.milk_quantity') }}</div>
                    <div class="fs-5"><x-litres :quantity="$figures['milk_quantity']" /></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.expected_amount') }}</div>
                    <div class="fs-5">
                        @if ($figures['expected_amount'] !== null)
                            <x-money :amount="$figures['expected_amount']" />
                        @else
                            <span class="text-body-secondary">&mdash;</span>
                        @endif
                    </div>
                    <div class="small text-body-secondary">{{ __('buyers.settlement.help.expected_amount') }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.statement_amount') }}</div>
                    <div class="fs-5">
                        @if ($settlement->hasStatement())
                            <x-money :amount="$settlement->statement_amount" />
                        @else
                            <span class="text-body-secondary">&mdash;</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.difference') }}</div>
                    <div class="fs-5">
                        @if ($settlement->difference !== null)
                            <x-money :amount="$settlement->difference" signed />
                        @else
                            <span class="text-body-secondary">&mdash;</span>
                        @endif
                    </div>
                    @if ($settlement->isFinalized())
                        <div class="small text-body-secondary">
                            {{ $settlement->hasDifference()
                                ? __('buyers.settlement.difference_recorded')
                                : ($settlement->hasStatement()
                                    ? __('buyers.settlement.no_difference')
                                    : __('buyers.settlement.no_statement')) }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($settlement->isDraft())
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">{{ __('buyers.settlement.finalize') }}</h2>
                <p class="small text-body-secondary">{{ __('buyers.settlement.help.statement_amount') }}</p>

                <form method="POST" action="{{ route('mandalis.settlements.update', [$mandali, $settlement]) }}"
                      class="row g-2 align-items-end mb-3">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="period_start" value="{{ $settlement->period_start->toDateString() }}">
                    <input type="hidden" name="period_end" value="{{ $settlement->period_end->toDateString() }}">

                    <div class="col-12 col-md-3">
                        <label for="statement_amount" class="form-label small mb-1">
                            {{ __('buyers.settlement.fields.statement_amount') }}
                        </label>
                        <input type="number" step="0.01" min="0" inputmode="decimal" class="form-control form-control-sm"
                               id="statement_amount" name="statement_amount"
                               value="{{ old('statement_amount', $settlement->statement_amount) }}">
                    </div>
                    <div class="col-12 col-md-5">
                        <label for="notes" class="form-label small mb-1">{{ __('buyers.settlement.fields.notes') }}</label>
                        <input type="text" class="form-control form-control-sm" id="notes" name="notes"
                               value="{{ old('notes', $settlement->notes) }}" maxlength="1000">
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            {{ __('app.actions.save_changes') }}
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('mandalis.settlements.finalize', [$mandali, $settlement]) }}"
                      onsubmit="return confirm('{{ __('buyers.settlement.finalize_confirm') }}');">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-lock me-1" aria-hidden="true"></i>{{ __('buyers.settlement.finalize') }}
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if ($settlement->isFinalized())
        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-4">
                <div>
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.amount_due') }}</div>
                    <div class="fs-5"><x-money :amount="$settlement->amountDue()" /></div>
                </div>
                <div>
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.paid') }}</div>
                    <div class="fs-5"><x-money :amount="$settlement->paidAmount()" /></div>
                </div>
                <div>
                    <div class="small text-body-secondary">{{ __('buyers.settlement.fields.remaining') }}</div>
                    <div class="fs-5"><x-money :amount="$settlement->remainingAmount()" /></div>
                </div>
            </div>
        </div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body border-bottom">
            <h2 class="h6 mb-0">{{ __('buyers.settlement.deliveries_in_period') }}</h2>
        </div>

        @if ($sales->isEmpty())
            <x-empty-state icon="inbox" :title="__('buyers.mandali.no_deliveries')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">{{ __('buyers.settlement.deliveries_in_period') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('buyers.sale.fields.date') }}</th>
                            <th scope="col">{{ __('buyers.sale.fields.shift') }}</th>
                            <th scope="col">{{ __('buyers.sale.fields.milk_type') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.sale.fields.quantity') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.sale.fields.fat') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.sale.fields.snf') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.sale.fields.rate') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.sale.fields.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            <tr>
                                <td class="text-nowrap">{{ $sale->sale_date->translatedFormat('d-m-Y') }}</td>
                                <td>{{ $sale->shift->label() }}</td>
                                <td>{{ $sale->milk_type->label() }}</td>
                                <td class="text-end"><x-litres :quantity="$sale->quantity" :unit="false" /></td>
                                <td class="text-end">{{ $sale->fat_percentage !== null ? number_format((float) $sale->fat_percentage, 2) : '—' }}</td>
                                <td class="text-end">{{ $sale->snf_percentage !== null ? number_format((float) $sale->snf_percentage, 2) : '—' }}</td>
                                <td class="text-end"><x-money :amount="$sale->unit_rate" muted /></td>
                                <td class="text-end"><x-money :amount="$sale->amount" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($adjustments->isNotEmpty())
        @include('buyers.channel._adjustments', ['adjustments' => $adjustments])
    @endif

    @if ($settlement->isFinalized() && $payments->isNotEmpty())
        <div class="card shadow-sm mb-3">
            <div class="card-body border-bottom">
                <h2 class="h6 mb-0">{{ __('buyers.payment.title') }}</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">{{ __('buyers.payment.title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('buyers.sale.fields.date') }}</th>
                            <th scope="col" class="text-end">{{ __('buyers.sale.fields.amount') }}</th>
                            <th scope="col">{{ __('customers.fields.payment_method') }}</th>
                            <th scope="col">{{ __('customers.fields.received_into') }}</th>
                            <th scope="col">{{ __('app.labels.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr @class(['text-body-secondary' => $payment->isCancelled()])>
                                <td class="text-nowrap">{{ $payment->payment_date->translatedFormat('d-m-Y') }}</td>
                                <td class="text-end"><x-money :amount="$payment->amount" /></td>
                                <td>{{ $payment->paymentMethod?->name ?: '—' }}</td>
                                <td>{{ $payment->account?->name ?: '—' }}</td>
                                <td>
                                    @if ($payment->isCancelled())
                                        <span class="badge rounded-pill text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                            {{ __('buyers.settlement_statuses.cancelled') }}
                                        </span>
                                    @else
                                        <x-status-badge :active="true" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top small text-body-secondary">
                {{ __('buyers.settlement.cancel_help') }}
            </div>
        </div>
    @endif

    @unless ($settlement->isCancelled())
        <div class="card shadow-sm border-danger-subtle">
            <div class="card-body">
                <h2 class="h6">{{ __('buyers.settlement.cancel') }}</h2>
                <p class="small text-body-secondary">{{ __('buyers.settlement.cancel_help') }}</p>

                <form method="POST" action="{{ route('mandalis.settlements.cancel', [$mandali, $settlement]) }}"
                      class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    <div class="col-12 col-md-8">
                        <label for="cancellation_reason" class="form-label small mb-1">
                            {{ __('milk.fields.cancellation_reason') }} *
                        </label>
                        <input type="text" class="form-control @error('cancellation_reason') is-invalid @enderror"
                               id="cancellation_reason" name="cancellation_reason"
                               minlength="5" maxlength="1000" required>
                        @error('cancellation_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="submit" class="btn btn-danger">{{ __('buyers.settlement.cancel') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endunless
@endsection
