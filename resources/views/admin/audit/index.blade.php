@extends('layouts.app')

@section('title', __('audit.title'))

@section('header')
    <x-page-header :title="__('audit.title')" :subtitle="__('audit.subtitle')" />
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('admin.audit.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="search" class="form-label small mb-1">{{ __('audit.filters.search') }}</label>
                    <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label for="user_id" class="form-label small mb-1">{{ __('audit.filters.user') }}</label>
                    <select id="user_id" name="user_id" class="form-select form-select-sm">
                        <option value="">{{ __('audit.filters.all_users') }}</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor->id }}" @selected((int) ($filters['user_id'] ?? 0) === $actor->id)>
                                {{ $actor->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="action" class="form-label small mb-1">{{ __('audit.filters.action') }}</label>
                    <select id="action" name="action" class="form-select form-select-sm">
                        <option value="">{{ __('audit.filters.all_actions') }}</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action->value }}" @selected(($filters['action'] ?? '') === $action->value)>
                                {{ $action->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="auditable_type" class="form-label small mb-1">{{ __('audit.filters.type') }}</label>
                    <select id="auditable_type" name="auditable_type" class="form-select form-select-sm">
                        <option value="">{{ __('audit.filters.all_types') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['auditable_type'] ?? '') === $type)>
                                {{ $type }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label for="from" class="form-label small mb-1">{{ __('audit.filters.from') }}</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-1">
                    <label for="to" class="form-label small mb-1">{{ __('audit.filters.to') }}</label>
                    <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary flex-grow-1">
                        {{ __('app.actions.filter') }}
                    </button>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                </div>
            </form>
        </div>

        @if ($logs->isEmpty())
            <div class="card-body">
                <x-empty-state icon="clock-history" :description="__('audit.empty')" />
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-sticky-head align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('audit.columns.when') }}</th>
                            <th scope="col">{{ __('audit.columns.user') }}</th>
                            <th scope="col">{{ __('audit.columns.action') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ __('audit.columns.type') }}</th>
                            <th scope="col">{{ __('audit.columns.subject') }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">{{ __('audit.columns.summary') }}</th>
                            <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="small text-nowrap">{{ $log->created_at->translatedFormat('d-m-Y H:i') }}</td>
                                <td class="small">
                                    {{ $log->user?->name ?? __('audit.detail.system') }}
                                </td>
                                <td>
                                    <span class="badge text-{{ $log->action->badge() }}-emphasis bg-{{ $log->action->badge() }}-subtle border border-{{ $log->action->badge() }}-subtle">
                                        {{ $log->action->label() }}
                                    </span>
                                </td>
                                <td class="d-none d-md-table-cell small font-monospace">{{ $log->auditable_type }}</td>
                                <td class="small">{{ $log->subject }}</td>
                                <td class="d-none d-lg-table-cell small text-body-secondary font-monospace">
                                    {{ $log->summary() }}
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.audit.show', $log) }}"
                                       class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($logs->hasPages())
                <div class="card-body border-top">{{ $logs->links() }}</div>
            @endif
        @endif
    </div>

    <p class="small text-body-secondary mt-3 mb-0">
        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>{{ __('audit.redacted_note') }}
    </p>
@endsection
