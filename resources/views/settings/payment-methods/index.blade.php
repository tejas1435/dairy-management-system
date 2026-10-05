@extends('layouts.app')

@section('title', __('settings.payment_methods.title'))

@section('header')
    <x-page-header :title="__('settings.payment_methods.title')"
                   :subtitle="__('settings.payment_methods.subtitle')" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('settings.payment_methods.columns.name') }}</th>
                                <th scope="col">{{ __('settings.payment_methods.columns.code') }}</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">
                                    {{ __('settings.payment_methods.columns.usage') }}
                                </th>
                                <th scope="col">{{ __('app.labels.status') }}</th>
                                <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($methods as $method)
                                @php
                                    $inUse = $method->partner_contributions_count + $method->funding_allocations_count;
                                @endphp
                                <tr>
                                    <td>
                                        <form method="POST" action="{{ route('settings.payment-methods.update', $method) }}"
                                              class="d-flex gap-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="name" value="{{ $method->name }}"
                                                   class="form-control form-control-sm" required
                                                   aria-label="{{ __('settings.payment_methods.fields.name') }}">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    aria-label="{{ __('app.actions.save') }}">
                                                <i class="bi bi-check-lg" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td><code class="small">{{ $method->code }}</code></td>
                                    <td class="text-end d-none d-md-table-cell small text-body-secondary">{{ $inUse }}</td>
                                    <td><x-status-badge :active="$method->is_active" /></td>
                                    <td class="text-end text-nowrap">
                                        <form method="POST" action="{{ route('settings.payment-methods.status.update', $method) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $method->is_active ? 0 : 1 }}">
                                            <button type="submit"
                                                    class="btn btn-sm {{ $method->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $method->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                            </button>
                                        </form>

                                        {{-- Delete is offered only where the server would allow it. --}}
                                        @if (! $method->is_system && $inUse === 0)
                                            <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm('{{ __('app.actions.confirm') }}');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="small text-body-secondary mt-3">
                <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('settings.payment_methods.help.code_fixed') }}</p>
                <p class="mb-0"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ __('settings.payment_methods.help.no_processing') }}</p>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-3">{{ __('settings.payment_methods.add') }}</h3>

                    <form method="POST" action="{{ route('settings.payment-methods.store') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="new_name" class="form-label">
                                {{ __('settings.payment_methods.fields.name') }} *
                            </label>
                            <input type="text" id="new_name" name="name" value="{{ old('name') }}"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="new_code" class="form-label">
                                {{ __('settings.payment_methods.fields.code') }} *
                            </label>
                            <input type="text" id="new_code" name="code" value="{{ old('code') }}"
                                   class="form-control font-monospace @error('code') is-invalid @enderror" required>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-sm btn-primary">{{ __('app.actions.create') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
