@extends('layouts.app')

@section('title', __('roles.title'))

@section('header')
    <x-page-header :title="__('roles.title')" :subtitle="__('roles.subtitle')">
        <a href="{{ route('admin.roles.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('roles.create_title') }}
        </a>
    </x-page-header>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('roles.columns.name') }}</th>
                        <th scope="col">{{ __('roles.columns.type') }}</th>
                        <th scope="col" class="text-end">{{ __('roles.columns.permissions') }}</th>
                        <th scope="col" class="text-end">{{ __('roles.columns.users') }}</th>
                        <th scope="col" class="text-end">{{ __('app.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roles as $role)
                        @php $isSystem = \App\Support\RoleCatalog::isSystem($role->name); @endphp
                        <tr>
                            <td class="fw-medium">{{ $role->name }}</td>
                            <td>
                                <span class="badge {{ $isSystem
                                    ? 'text-info-emphasis bg-info-subtle border border-info-subtle'
                                    : 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle' }}">
                                    {{ $isSystem ? __('roles.system') : __('roles.custom') }}
                                </span>
                            </td>
                            <td class="text-end">{{ $role->permissions_count }}</td>
                            <td class="text-end">{{ $role->users_count }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.roles.edit', $role) }}"
                                   class="btn btn-sm btn-outline-secondary">{{ __('app.actions.edit') }}</a>

                                {{-- System roles have no delete control at all, matching the server rule. --}}
                                @unless ($isSystem)
                                    <form method="POST" action="{{ route('admin.roles.destroy', $role) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('{{ __('app.actions.confirm') }}');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            {{ __('app.actions.delete') }}
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

    <p class="small text-body-secondary mt-3 mb-0">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('roles.help.immediate') }}
    </p>
@endsection
