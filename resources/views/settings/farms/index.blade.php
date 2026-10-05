@extends('layouts.app')

@section('title', __('settings.farm.title'))

@section('header')
    <x-page-header :title="__('settings.farm.title')" :subtitle="__('settings.farm.subtitle')">
        <a href="{{ route('settings.farms.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('settings.farm.create_title') }}
        </a>
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('settings.farm.columns.name') }}</th>
                        <th scope="col">{{ __('settings.farm.columns.code') }}</th>
                        <th scope="col" class="d-none d-md-table-cell">{{ __('settings.farm.columns.address') }}</th>
                        <th scope="col">{{ __('settings.farm.columns.status') }}</th>
                        <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($farms as $farm)
                        <tr>
                            <td>
                                <span class="fw-medium">{{ $farm->name }}</span>
                                @if ($farm->is_primary)
                                    <span class="badge text-primary-emphasis bg-primary-subtle border border-primary-subtle ms-1">
                                        {{ __('app.status.primary') }}
                                    </span>
                                @endif
                            </td>
                            <td><code>{{ $farm->code }}</code></td>
                            <td class="d-none d-md-table-cell small text-body-secondary">
                                {{ $farm->address ?: '—' }}
                            </td>
                            <td><x-status-badge :active="$farm->is_active" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('settings.farms.edit', $farm) }}"
                                   class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>

                                @unless ($farm->is_primary)
                                    @if ($farm->is_active)
                                        <form method="POST" action="{{ route('settings.farms.primary', $farm) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                {{ __('settings.farm.make_primary') }}
                                            </button>
                                        </form>
                                    @endif

                                    {{--
                                        The primary farm has no deactivate control, because
                                        operational screens resolve it automatically and the
                                        action refuses to disable it.
                                    --}}
                                    <form method="POST" action="{{ route('settings.farms.status.update', $farm) }}"
                                          class="d-inline">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="is_active" value="{{ $farm->is_active ? 0 : 1 }}">
                                        <button type="submit"
                                                class="btn btn-sm {{ $farm->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                            {{ $farm->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                        </button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="small text-body-secondary mt-3">
        <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('settings.farm.help.primary') }}</p>
        <p class="mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('settings.farm.help.no_selector') }}</p>
    </div>
@endsection
