<div class="card shadow-sm">
    <div class="card-body border-bottom">
        <h3 class="h6 mb-0">{{ __('customers.payments.title') }}</h3>
        <div class="small text-body-secondary">{{ __('customers.payments.subtitle') }}</div>
    </div>

    @can('recordPayment', $customer)
        <div class="card-body border-bottom">
            <form method="POST" action="{{ route('customers.payments.store', $customer) }}"
                  class="row g-2 align-items-end">
                @csrf

                <div class="col-6 col-md-2">
                    <label for="payment-date" class="form-label small mb-1">{{ __('customers.fields.date') }}</label>
                    <input type="date" class="form-control form-control-sm @error('payment_date') is-invalid @enderror"
                           id="payment-date" name="payment_date"
                           value="{{ old('payment_date', $today) }}" required>
                    @error('payment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-6 col-md-2">
                    <label for="payment-amount" class="form-label small mb-1">{{ __('customers.fields.amount') }}</label>
                    <input type="number" class="form-control form-control-sm text-end font-monospace @error('amount') is-invalid @enderror"
                           id="payment-amount" name="amount" value="{{ old('amount') }}"
                           step="0.01" min="0.01" inputmode="decimal" required>
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12 col-md-3">
                    <label for="payment-account" class="form-label small mb-1">
                        {{ __('customers.fields.received_into') }}
                    </label>
                    <select class="form-select form-select-sm @error('financial_account_id') is-invalid @enderror"
                            id="payment-account" name="financial_account_id" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}"
                                @selected((int) old('financial_account_id') === (int) $account->id)>
                                {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-6 col-md-2">
                    <label for="payment-method" class="form-label small mb-1">
                        {{ __('customers.fields.payment_method') }}
                    </label>
                    <select class="form-select form-select-sm" id="payment-method" name="payment_method_id">
                        <option value="">—</option>
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}"
                                @selected((int) old('payment_method_id') === (int) $method->id)>
                                {{ $method->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label for="payment-reference" class="form-label small mb-1">
                        {{ __('customers.fields.reference') }}
                    </label>
                    <input type="text" class="form-control form-control-sm" id="payment-reference"
                           name="reference" value="{{ old('reference') }}" maxlength="255">
                </div>

                <div class="col-12 col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        {{ __('app.actions.save') }}
                    </button>
                </div>

                <div class="col-12">
                    <div class="small text-body-secondary">{{ __('customers.payments.help') }}</div>
                </div>
            </form>
        </div>
    @endcan

    @if ($payments->isEmpty())
        <div class="card-body">
            <x-empty-state icon="cash-coin" :title="__('customers.payments.none')"
                           :description="__('customers.payments.subtitle')" />
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('customers.fields.date') }}</th>
                        <th scope="col" class="text-end">{{ __('customers.fields.amount') }}</th>
                        <th scope="col">{{ __('customers.fields.payment_method') }}</th>
                        <th scope="col">{{ __('customers.fields.received_into') }}</th>
                        <th scope="col">{{ __('customers.fields.reference') }}</th>
                        <th scope="col" class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr class="{{ $payment->isCancelled() ? 'text-body-secondary' : '' }}">
                            <td class="small text-nowrap">
                                {{ $payment->payment_date->translatedFormat('d-m-Y') }}
                                @if ($payment->isCancelled())
                                    <span class="badge text-danger-emphasis bg-danger-subtle border border-danger-subtle">
                                        {{ __('finance.statuses.cancelled') }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-money :amount="$payment->amount" :muted="$payment->isCancelled()" />
                            </td>
                            <td class="small">{{ $payment->paymentMethod?->name ?? '—' }}</td>
                            <td class="small">{{ $payment->account?->name ?? '—' }}</td>
                            <td class="small">
                                {{ $payment->reference ?? '—' }}
                                @if ($payment->cancellation_reason)
                                    <div class="text-danger-emphasis">{{ $payment->cancellation_reason }}</div>
                                @endif
                            </td>
                            <td class="text-end">
                                @if (! $payment->isCancelled())
                                    @can('cancelPayment', $customer)
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#cancel-payment-{{ $payment->id }}">
                                            {{ __('app.actions.cancel') }}
                                        </button>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@can('cancelPayment', $customer)
    @foreach ($payments->reject->isCancelled() as $payment)
        <div class="modal fade" id="cancel-payment-{{ $payment->id }}" tabindex="-1"
             aria-labelledby="cancel-payment-label-{{ $payment->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('customers.payments.cancel', $payment) }}"
                      class="modal-content">
                    @csrf
                    @method('PUT')

                    <div class="modal-header">
                        <h5 class="modal-title h6" id="cancel-payment-label-{{ $payment->id }}">
                            {{ __('customers.payments.cancel_title') }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('app.actions.close') }}"></button>
                    </div>

                    <div class="modal-body">
                        <p class="small text-body-secondary">{{ __('customers.payments.cancel_help') }}</p>

                        <dl class="row small mb-3">
                            <dt class="col-5">{{ __('customers.fields.date') }}</dt>
                            <dd class="col-7">{{ $payment->payment_date->translatedFormat('d-m-Y') }}</dd>
                            <dt class="col-5">{{ __('customers.fields.amount') }}</dt>
                            <dd class="col-7"><x-money :amount="$payment->amount" /></dd>
                            <dt class="col-5">{{ __('customers.fields.received_into') }}</dt>
                            <dd class="col-7">{{ $payment->account?->name ?? '—' }}</dd>
                        </dl>

                        <label for="payment-cancel-reason-{{ $payment->id }}" class="form-label">
                            {{ __('customers.fields.cancellation_reason') }}
                        </label>
                        <textarea class="form-control" id="payment-cancel-reason-{{ $payment->id }}"
                                  name="cancellation_reason" rows="3" required minlength="5"
                                  maxlength="1000"></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            {{ __('app.actions.close') }}
                        </button>
                        <button type="submit" class="btn btn-danger">{{ __('app.actions.confirm') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endcan
