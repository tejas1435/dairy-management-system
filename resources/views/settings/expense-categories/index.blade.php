@extends('layouts.app')

@section('title', __('settings.expense_categories.title'))

@section('header')
    <x-page-header :title="__('settings.expense_categories.title')"
                   :subtitle="__('settings.expense_categories.subtitle')" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('settings.expense_categories.columns.name') }}</th>
                                <th scope="col">{{ __('settings.expense_categories.columns.code') }}</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">
                                    {{ __('settings.expense_categories.columns.expenses') }}
                                </th>
                                <th scope="col">{{ __('app.labels.status') }}</th>
                                <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($categories as $category)
                                <tr>
                                    <td>
                                        <form method="POST"
                                              action="{{ route('settings.expense-categories.update', $category) }}"
                                              class="d-flex gap-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="name" value="{{ $category->name }}"
                                                   class="form-control form-control-sm" required
                                                   aria-label="{{ __('settings.expense_categories.fields.name') }}">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    aria-label="{{ __('app.actions.save') }}">
                                                <i class="bi bi-check-lg" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td><code class="small">{{ $category->code }}</code></td>
                                    <td class="text-end d-none d-md-table-cell small text-body-secondary">
                                        {{ $category->expenses_count }}
                                    </td>
                                    <td><x-status-badge :active="$category->is_active" /></td>
                                    <td class="text-end text-nowrap">
                                        <form method="POST"
                                              action="{{ route('settings.expense-categories.status.update', $category) }}"
                                              class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="is_active" value="{{ $category->is_active ? 0 : 1 }}">
                                            <button type="submit"
                                                    class="btn btn-sm {{ $category->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $category->is_active ? __('app.actions.deactivate') : __('app.actions.activate') }}
                                            </button>
                                        </form>

                                        @if (! $category->is_system && $category->expenses_count === 0)
                                            <form method="POST"
                                                  action="{{ route('settings.expense-categories.destroy', $category) }}"
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

            <p class="small text-body-secondary mt-3 mb-0">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('settings.expense_categories.help.code_fixed') }}
            </p>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6 mb-3">{{ __('settings.expense_categories.add') }}</h3>

                    <form method="POST" action="{{ route('settings.expense-categories.store') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="new_name" class="form-label">
                                {{ __('settings.expense_categories.fields.name') }} *
                            </label>
                            <input type="text" id="new_name" name="name" value="{{ old('name') }}"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="new_code" class="form-label">
                                {{ __('settings.expense_categories.fields.code') }} *
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
