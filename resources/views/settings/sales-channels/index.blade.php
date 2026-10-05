@extends('layouts.app')

@section('title', __('settings.sales_channels.title'))

@section('header')
    <x-page-header :title="__('settings.sales_channels.title')"
                   :subtitle="__('settings.sales_channels.subtitle')" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('settings.sales_channels.columns.name') }}</th>
                                <th scope="col">{{ __('settings.sales_channels.columns.slug') }}</th>
                                <th scope="col">{{ __('settings.sales_channels.columns.type') }}</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">
                                    {{ __('settings.sales_channels.columns.buyers') }}
                                </th>
                                <th scope="col">{{ __('app.labels.status') }}</th>
                                <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($channels as $channel)
                                <tr>
                                    <td>
                                        {{-- The display name is editable even on a
                                             system channel; only the slug is fixed. --}}
                                        <form method="POST" action="{{ route('settings.sales-channels.update', $channel) }}"
                                              class="d-flex gap-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="name" value="{{ $channel->name }}"
                                                   class="form-control form-control-sm" required
                                                   aria-label="{{ __('settings.sales_channels.fields.name') }}">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    aria-label="{{ __('app.actions.save') }}">
                                                <i class="bi bi-check-lg" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td><code class="small">{{ $channel->slug }}</code></td>
                                    <td>
                                        <span class="badge {{ $channel->isSystemChannel()
                                            ? 'text-info-emphasis bg-info-subtle border border-info-subtle'
                                            : 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle' }}">
                                            {{ $channel->isSystemChannel()
                                                ? __('settings.sales_channels.system')
                                                : __('settings.sales_channels.custom') }}
                                        </span>
                                    </td>
                                    <td class="text-end d-none d-md-table-cell small text-body-secondary">
                                        {{ $channel->buyers_count }}
                                    </td>
                                    <td><x-status-badge :active="$channel->is_active" /></td>
                                    <td class="text-end text-nowrap">
                                        <form method="POST"
                                              action="{{ route('settings.sales-channels.status.update', $channel) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $channel->is_active ? 0 : 1 }}">
                                            <button type="submit"
                                                    class="btn btn-sm {{ $channel->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $channel->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                            </button>
                                        </form>

                                        {{-- No delete control for system channels or
                                             channels with buyers: the server refuses
                                             both, so offering the button would only
                                             produce an error. --}}
                                        @if (! $channel->isSystemChannel() && $channel->buyers_count === 0)
                                            <form method="POST"
                                                  action="{{ route('settings.sales-channels.destroy', $channel) }}"
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
                <p class="mb-1"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('settings.sales_channels.help.system_fixed') }}</p>
                <p class="mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('settings.sales_channels.help.custom_generic') }}</p>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-3">{{ __('settings.sales_channels.add') }}</h3>

                    <form method="POST" action="{{ route('settings.sales-channels.store') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="new_name" class="form-label">
                                {{ __('settings.sales_channels.fields.name') }} *
                            </label>
                            <input type="text" id="new_name" name="name" value="{{ old('name') }}"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="new_slug" class="form-label">
                                {{ __('settings.sales_channels.fields.slug') }} *
                            </label>
                            <input type="text" id="new_slug" name="slug" value="{{ old('slug') }}"
                                   class="form-control font-monospace @error('slug') is-invalid @enderror" required>
                            @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-sm btn-primary">{{ __('app.actions.create') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
