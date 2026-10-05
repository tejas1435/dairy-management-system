@extends('layouts.app')

@section('title', __('audit.detail_title'))

@section('header')
    <x-page-header :title="__('audit.detail_title')" :subtitle="$log->subject"
                   :back="route('admin.audit.index')" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-3">{{ __('audit.columns.summary') }}</h3>

                    @php $changes = $log->changes(); @endphp

                    @if ($changes === [])
                        <p class="text-body-secondary small mb-0">{{ __('audit.detail.no_changes') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('audit.detail.field') }}</th>
                                        <th scope="col">{{ __('audit.detail.before') }}</th>
                                        <th scope="col">{{ __('audit.detail.after') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($changes as $field => $pair)
                                        <tr>
                                            <td class="font-monospace small">{{ $field }}</td>
                                            <td class="small text-body-secondary">
                                                @include('admin.audit._value', ['value' => $pair['old']])
                                            </td>
                                            <td class="small">
                                                @include('admin.audit._value', ['value' => $pair['new']])
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-3">{{ __('audit.detail.context') }}</h3>

                    <dl class="row row-cols-1 mb-0 small">
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.columns.when') }}</dt>
                            <dd class="mb-0 text-end">{{ $log->created_at->translatedFormat('d-m-Y H:i:s') }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.columns.user') }}</dt>
                            <dd class="mb-0 text-end">
                                {{ $log->user?->name ?? __('audit.detail.system') }}
                                @if ($log->user)
                                    <div class="text-body-secondary">{{ $log->user->email }}</div>
                                @endif
                            </dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.columns.action') }}</dt>
                            <dd class="mb-0 text-end">{{ $log->action->label() }}</dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.columns.type') }}</dt>
                            <dd class="mb-0 text-end font-monospace">
                                {{ $log->auditable_type }} #{{ $log->auditable_id }}
                            </dd>
                        </div>
                        <div class="col d-flex justify-content-between border-bottom py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.detail.ip') }}</dt>
                            <dd class="mb-0 text-end font-monospace">{{ $log->ip_address ?? '—' }}</dd>
                        </div>
                        <div class="col py-1">
                            <dt class="fw-normal text-body-secondary">{{ __('audit.detail.user_agent') }}</dt>
                            <dd class="mb-0 small text-body-secondary text-break">{{ $log->user_agent ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
