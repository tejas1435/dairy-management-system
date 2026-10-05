@extends('layouts.app')

@section('title', __('buyers.settlement.title'))

@section('header')
    <x-page-header :title="__('buyers.settlement.title')"
                   :subtitle="$mandali->name.' · '.__('buyers.settlement.subtitle')"
                   :back="route('mandalis.show', $mandali)">
        <a href="{{ route('mandalis.settlements.create', $mandali) }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('buyers.settlement.create') }}
        </a>
    </x-page-header>
@endsection

@section('content')
    <x-alerts />

    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('mandalis.settlements.index', $mandali) }}"
                  class="row g-2 align-items-end">
                <div class="col-8 col-md-3">
                    <label for="status" class="form-label small mb-1">{{ __('app.labels.status') }}</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">{{ __('app.status.all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">{{ __('app.actions.filter') }}</button>
                </div>
            </form>
        </div>

        @if ($settlements->isEmpty())
            <x-empty-state icon="receipt"
                           :title="__('buyers.settlement.empty')"
                           :description="__('buyers.settlement.empty_help')" />
        @else
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
                            <th scope="col" class="text-end">{{ __('buyers.settlement.fields.amount_due') }}</th>
                            <th scope="col">{{ __('app.labels.status') }}</th>
                            <th scope="col"></th>
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
                                        <x-money :amount="$settlement->amountDue()" />
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
                                    <a href="{{ route('mandalis.settlements.show', [$mandali, $settlement]) }}"
                                       class="btn btn-sm btn-outline-secondary">{{ __('buyers.settlement.singular') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-body border-top">{{ $settlements->links() }}</div>
        @endif
    </div>
@endsection
