{{-- Shared create/edit fields for a farm. $farm is null when creating. --}}
@php $isEdit = isset($farm) && $farm !== null; @endphp

<div class="row g-3">
    <div class="col-12 col-md-8">
        <label for="name" class="form-label">{{ __('settings.farm.fields.name') }} *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $farm->name ?? '') }}"
               class="form-control @error('name') is-invalid @enderror" required autofocus>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-4">
        <label for="code" class="form-label">{{ __('settings.farm.fields.code') }} *</label>
        <input type="text" id="code" name="code" value="{{ old('code', $farm->code ?? '') }}"
               class="form-control font-monospace text-uppercase @error('code') is-invalid @enderror"
               required maxlength="20">
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">{{ __('settings.farm.help.code') }}</div>
    </div>

    <div class="col-12">
        <label for="address" class="form-label">
            {{ __('settings.farm.fields.address') }}
            <span class="text-body-secondary small">({{ __('app.labels.optional') }})</span>
        </label>
        <textarea id="address" name="address" rows="3"
                  class="form-control @error('address') is-invalid @enderror">{{ old('address', $farm->address ?? '') }}</textarea>
        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @unless ($isEdit)
        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                       @checked(old('is_active', true))>
                <label for="is_active" class="form-check-label">{{ __('settings.farm.fields.is_active') }}</label>
            </div>
        </div>
    @endunless
</div>
