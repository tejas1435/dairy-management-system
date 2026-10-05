{{--
    Shared create/edit fields for a role.

    $role is null when creating. Two locks mirror the server rules exactly, so the
    form never offers something the controller will refuse:
      - a system role's name is read-only;
      - Super Admin's permissions are shown but not editable.
--}}
@php
    $isEdit = isset($role) && $role !== null;
    $isSystem = $isSystem ?? false;
    $permissionsLocked = $permissionsLocked ?? false;
    $selected = old('permissions', $assigned);
@endphp

<div class="mb-4">
    <label for="name" class="form-label">{{ __('roles.fields.name') }} *</label>
    <input type="text" id="name" name="name" value="{{ old('name', $role->name ?? '') }}"
           class="form-control @error('name') is-invalid @enderror"
           required @readonly($isSystem) @if ($isSystem) aria-describedby="name-help" @endif>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($isSystem)
        <div id="name-help" class="form-text">{{ __('roles.help.system_name') }}</div>
    @endif
</div>

@if ($permissionsLocked)
    <div class="alert alert-info d-flex align-items-start gap-2 small" role="note">
        <i class="bi bi-shield-check flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ __('roles.help.super_admin') }}</div>
    </div>
@endif

<fieldset @disabled($permissionsLocked)>
    <legend class="form-label fs-6 mb-1">{{ __('roles.fields.permissions') }}</legend>
    <p class="form-text mt-0 mb-3">{{ __('roles.help.immediate') }}</p>

    <div class="row g-3">
        @foreach ($groups as $group => $permissions)
            <div class="col-12 col-lg-6">
                <div class="border rounded p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="h6 mb-0">{{ __('roles.groups.'.$group) }}</h4>
                        @unless ($permissionsLocked)
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none"
                                    data-permission-group-toggle="{{ $group }}">
                                {{ __('roles.help.select_all') }}
                            </button>
                        @endunless
                    </div>

                    <div class="d-flex flex-column gap-1" data-permission-group="{{ $group }}">
                        @foreach ($permissions as $permission)
                            <div class="form-check mb-0">
                                <input type="checkbox" class="form-check-input"
                                       id="perm-{{ \Illuminate\Support\Str::slug($permission) }}"
                                       name="permissions[]" value="{{ $permission }}"
                                       @checked(in_array($permission, $selected, true))
                                       @disabled($permissionsLocked)>
                                <label class="form-check-label small font-monospace"
                                       for="perm-{{ \Illuminate\Support\Str::slug($permission) }}">
                                    {{ $permission }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @error('permissions')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
</fieldset>
