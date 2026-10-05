@extends('layouts.app')

@section('title', $customer->name)

@section('header')
    <x-page-header :title="$customer->name" :subtitle="__('customers.profile')"
                   :back="route('customers.index')">
        @can('update', $customer)
            <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>{{ __('customers.actions.edit_profile') }}
            </a>
        @endcan
    </x-page-header>
@endsection

@section('content')
    {{-- Identity, eligibility and the outstanding figure, above everything else. --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-6 col-md-4">
                            <div class="text-body-secondary">{{ __('customers.fields.mobile') }}</div>
                            <div class="font-monospace">{{ $customer->mobile ?? '—' }}</div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-body-secondary">{{ __('customers.fields.area') }}</div>
                            <div>{{ $customer->area ?? '—' }}</div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-body-secondary">{{ __('customers.fields.payment_cycle') }}</div>
                            <div>{{ $customer->payment_cycle ?? '—' }}</div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-body-secondary">{{ __('customers.fields.start_date') }}</div>
                            <div>{{ $customer->start_date?->translatedFormat('d-m-Y') ?? '—' }}</div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-body-secondary">{{ __('customers.fields.status') }}</div>
                            <div>
                                @if ($customer->is_active)
                                    <span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">
                                        {{ __('customers.status.active') }}
                                    </span>
                                @else
                                    <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
                                        {{ __('customers.status.archived') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-body-secondary">{{ __('customers.eligibility.deliverable') }}</div>
                            <div>
                                @if ($eligibility->isDeliverable())
                                    <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                                    <span class="visually-hidden">{{ __('app.status.yes') }}</span>
                                @else
                                    <span class="text-warning-emphasis">{{ $eligibility->ineligibleMessage() }}</span>
                                @endif
                            </div>
                        </div>

                        @if ($customer->address)
                            <div class="col-12">
                                <div class="text-body-secondary">{{ __('customers.fields.address') }}</div>
                                <div>{{ $customer->address }}</div>
                            </div>
                        @endif

                        @if ($customer->delivery_note)
                            <div class="col-12">
                                <div class="text-body-secondary">{{ __('customers.fields.delivery_note') }}</div>
                                <div class="fst-italic">
                                    <i class="bi bi-sticky me-1" aria-hidden="true"></i>{{ $customer->delivery_note }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-0">{{ __('customers.outstanding.title') }}</h3>
                    <div class="small text-body-secondary">{{ __('customers.outstanding.formula') }}</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr>
                                <th scope="row" class="fw-normal">{{ __('customers.statement.sales') }}</th>
                                <td class="text-end"><x-money :amount="$outstanding['sales']" /></td>
                            </tr>
                            <tr>
                                <th scope="row" class="fw-normal">{{ __('customers.outstanding.adjustments') }}</th>
                                <td class="text-end">
                                    <x-money :amount="$outstanding['adjustments']" muted />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" class="fw-normal">{{ __('customers.statement.payments_received') }}</th>
                                <td class="text-end"><x-money :amount="$outstanding['payments']" /></td>
                            </tr>
                            <tr class="table-light">
                                <th scope="row">{{ __('customers.statement.outstanding') }}</th>
                                <td class="text-end">
                                    <x-money :amount="$outstanding['outstanding']" class="fs-6 fw-semibold" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-body">
                    <div class="small text-body-secondary">{{ __('customers.outstanding.adjustments_note') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Preferences and pauses. --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-body border-bottom">
                    <h3 class="h6 mb-0">{{ __('customers.preferences.title') }}</h3>
                    <div class="small text-body-secondary">{{ __('customers.reminders.explanation') }}</div>
                </div>

                @php $activePreferences = $customer->preferences->where('is_active', true); @endphp

                @if ($activePreferences->isEmpty())
                    <div class="card-body">
                        <div class="alert alert-warning small mb-0" role="alert">
                            {{ __('customers.preferences.none') }}
                        </div>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('milk.fields.milk_type') }}</th>
                                    <th scope="col" class="text-end">{{ __('customers.fields.morning_reminder') }}</th>
                                    <th scope="col" class="text-end">{{ __('customers.fields.evening_reminder') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($activePreferences as $preference)
                                    <tr>
                                        <td>{{ $preference->milk_type->label() }}</td>
                                        <td class="text-end">
                                            <x-litres :quantity="$preference->morningReminder()" muted />
                                        </td>
                                        <td class="text-end">
                                            <x-litres :quantity="$preference->eveningReminder()" muted />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-12 col-xl-7">
            @include('customers._pauses')
        </div>
    </div>

    {{-- Price overrides. --}}
    @include('customers._prices')

    {{-- Statement, ledger and payments. --}}
    @include('customers._statement')

    @include('customers._payments')
@endsection
