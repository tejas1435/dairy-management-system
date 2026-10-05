{{--
    Shared create/edit fields for a user account.

    $user is null when creating. Passwords are never rendered back into the form:
    the field is always empty, and on edit an empty value means "leave it alone".
--}}
@php
    $isEdit = isset($user) && $user !== null;
@endphp

<div class="row g-3">
    <div class="col-12 col-md-6">
        <label for="name" class="form-label">{{ __('users.fields.name') }} *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $user->name ?? '') }}"
               class="form-control @error('name') is-invalid @enderror" required autofocus>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6">
        <label for="email" class="form-label">{{ __('users.fields.email') }} *</label>
        <input type="email" id="email" name="email" value="{{ old('email', $user->email ?? '') }}"
               class="form-control @error('email') is-invalid @enderror" required autocomplete="off">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6">
        <label for="password" class="form-label">
            {{ __('users.fields.password') }} @unless ($isEdit) * @endunless
        </label>
        <input type="password" id="password" name="password"
               class="form-control @error('password') is-invalid @enderror"
               @unless ($isEdit) required @endunless autocomplete="new-password">
        @error('password')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        @if ($isEdit)
            <div class="form-text">{{ __('users.help.password_optional') }}</div>
        @endif
    </div>

    <div class="col-12 col-md-6">
        <label for="password_confirmation" class="form-label">
            {{ __('users.fields.confirm_password') }} @unless ($isEdit) * @endunless
        </label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               class="form-control" @unless ($isEdit) required @endunless autocomplete="new-password">
    </div>

    <div class="col-12 col-md-6">
        <label for="locale" class="form-label">{{ __('users.fields.locale') }} *</label>
        <select id="locale" name="locale" class="form-select @error('locale') is-invalid @enderror" required>
            @foreach ($locales as $locale)
                <option value="{{ $locale->value }}"
                    @selected(old('locale', $user->locale->value ?? \App\Enums\Locale::default()->value) === $locale->value)>
                    {{ $locale->nativeName() }}
                </option>
            @endforeach
        </select>
        @error('locale')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6 d-flex align-items-end">
        <div class="form-check">
            <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                   @checked(old('is_active', $user->is_active ?? true))>
            <label for="is_active" class="form-check-label">{{ __('users.fields.is_active') }}</label>
            <div class="form-text">{{ __('users.help.inactive') }}</div>
        </div>
    </div>

    <div class="col-12">
        <fieldset>
            <legend class="form-label mb-1 fs-6">{{ __('users.fields.roles') }}</legend>
            <p class="form-text mt-0 mb-2">{{ __('users.help.roles') }}</p>

            @php
                $selectedRoles = old('roles', $isEdit ? $user->roles->pluck('name')->all() : []);
            @endphp

            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-2">
                @foreach ($roles as $role)
                    <div class="col">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input"
                                   id="role-{{ $role->id }}" name="roles[]" value="{{ $role->name }}"
                                   @checked(in_array($role->name, $selectedRoles, true))>
                            <label class="form-check-label" for="role-{{ $role->id }}">{{ $role->name }}</label>
                        </div>
                    </div>
                @endforeach
            </div>
            @error('roles')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </fieldset>
    </div>
</div>
