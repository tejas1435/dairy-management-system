{{--
    Receipts, through the same BuyerPayment and the same action a customer receipt
    uses. The form is offered only when the buyer actually owes something: the action
    refuses a payment against a zero balance, and a form that invites a refusal is
    worse than no form.
--}}
<div class="card shadow-sm mb-3">
    <div class="card-body border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">{{ __('buyers.payment.title') }}</h2>
        <span class="small text-body-secondary">
            {{ __('buyers.ledger.outstanding') }}
            <x-money :amount="$breakdown['outstanding']" class="ms-1" />
        </span>
    </div>

    @can('recordPayment', $buyer)
        @if (bccomp($breakdown['outstanding'], '0.00', 2) > 0)
            <div class="card-body border-bottom">
                <form method="POST" action="{{ route('buyers.payments.store', $buyer) }}" class="row g-2 align-items-end">
                    @csrf

                    <div class="col-6 col-md-2">
                        <label for="payment_date" class="form-label small mb-1">{{ __('buyers.sale.fields.date') }} *</label>
                        <input type="date" class="form-control form-control-sm" id="payment_date" name="payment_date"
                               value="{{ old('payment_date', now()->toDateString()) }}" required>
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="amount" class="form-label small mb-1">{{ __('buyers.sale.fields.amount') }} *</label>
                        <input type="number" step="0.01" min="0.01" class="form-control form-control-sm"
                               id="amount" name="amount" value="{{ old('amount') }}" inputmode="decimal" required>
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="financial_account_id" class="form-label small mb-1">{{ __('customers.fields.received_into') }} *</label>
                        <select class="form-select form-select-sm" id="financial_account_id" name="financial_account_id" required>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('financial_account_id') === $account->id)>
                                    {{ $account->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="payment_method_id" class="form-label small mb-1">{{ __('customers.fields.payment_method') }}</label>
                        <select class="form-select form-select-sm" id="payment_method_id" name="payment_method_id">
                            <option value="">{{ __('app.status.none') }}</option>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->id }}" @selected((int) old('payment_method_id') === $method->id)>
                                    {{ $method->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @if ($buyer->isMandali())
                        @if ($payable->isNotEmpty())
                            <div class="col-12 col-md-2">
                                <label for="buyer_settlement_id" class="form-label small mb-1">{{ __('buyers.settlement.singular') }}</label>
                                <select class="form-select form-select-sm" id="buyer_settlement_id" name="buyer_settlement_id">
                                    <option value="">{{ __('buyers.payment.no_settlement') }}</option>
                                    @foreach ($payable as $settlement)
                                        <option value="{{ $settlement->id }}">
                                            {{ $settlement->periodLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    @endif

                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            {{ __('buyers.payment.record') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif
    @endcan

    @if ($payments->isEmpty())
        <x-empty-state icon="cash-coin" :title="__('buyers.payment.empty')" />
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ __('buyers.payment.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('buyers.sale.fields.date') }}</th>
                        <th scope="col" class="text-end">{{ __('buyers.sale.fields.amount') }}</th>
                        <th scope="col">{{ __('customers.fields.payment_method') }}</th>
                        <th scope="col">{{ __('customers.fields.received_into') }}</th>
                        <th scope="col">{{ __('buyers.settlement.singular') }}</th>
                        <th scope="col">{{ __('app.labels.status') }}</th>
                        <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr @class(['text-body-secondary' => $payment->isCancelled()])>
                            <td class="text-nowrap">{{ $payment->payment_date->translatedFormat('d-m-Y') }}</td>
                            <td class="text-end"><x-money :amount="$payment->amount" /></td>
                            <td>{{ $payment->paymentMethod?->name ?: '—' }}</td>
                            <td>{{ $payment->account?->name ?: '—' }}</td>
                            <td class="small">
                                {{ $payment->settlement ? $payment->settlement->periodLabel() : __('buyers.payment.no_settlement') }}
                            </td>
                            <td>
                                @if ($payment->isCancelled())
                                    <span class="badge rounded-pill text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                        {{ __('buyers.settlement_statuses.cancelled') }}
                                    </span>
                                    <div class="small">{{ $payment->cancellation_reason }}</div>
                                @else
                                    <x-status-badge :active="true" />
                                @endif
                            </td>
                            <td class="text-end">
                                @can('cancelPayment', $buyer)
                                    @unless ($payment->isCancelled())
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#cancel-payment-{{ $payment->id }}">
                                            {{ __('buyers.payment.cancel') }}
                                        </button>
                                    @endunless
                                @endcan
                            </td>
                        </tr>

                        @can('cancelPayment', $buyer)
                            @unless ($payment->isCancelled())
                                <tr class="collapse" id="cancel-payment-{{ $payment->id }}">
                                    <td colspan="7">
                                        <form method="POST" action="{{ route('buyers.payments.cancel', $payment) }}"
                                              class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-12 col-md-8">
                                                <label for="reason-{{ $payment->id }}" class="form-label small mb-1">
                                                    {{ __('milk.fields.cancellation_reason') }} *
                                                </label>
                                                <input type="text" class="form-control form-control-sm"
                                                       id="reason-{{ $payment->id }}" name="cancellation_reason"
                                                       minlength="5" maxlength="1000" required>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    {{ __('buyers.payment.cancel') }}
                                                </button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endunless
                        @endcan
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
