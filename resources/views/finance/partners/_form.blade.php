@php $isEdit = isset($partner) && $partner !== null; @endphp

<div class="row g-3">
    <div class="col-12 col-md-6">
        <label for="name" class="form-label">{{ __('partners.fields.name') }} *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $partner->name ?? '') }}"
               class="form-control @error('name') is-invalid @enderror" required autofocus>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-3">
        <label for="mobile" class="form-label">{{ __('partners.fields.mobile') }}</label>
        <input type="tel" id="mobile" name="mobile" value="{{ old('mobile', $partner->mobile ?? '') }}"
               class="form-control @error('mobile') is-invalid @enderror" inputmode="tel">
        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-3">
        <label for="joining_date" class="form-label">{{ __('partners.fields.joining_date') }}</label>
        <input type="date" id="joining_date" name="joining_date"
               value="{{ old('joining_date', $partner?->joining_date?->toDateString() ?? '') }}"
               class="form-control @error('joining_date') is-invalid @enderror">
        @error('joining_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6">
        <label for="email" class="form-label">{{ __('partners.fields.email') }}</label>
        <input type="email" id="email" name="email" value="{{ old('email', $partner->email ?? '') }}"
               class="form-control @error('email') is-invalid @enderror" inputmode="email">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @unless ($isEdit)
        <div class="col-12 col-md-6 d-flex align-items-end">
            <div class="form-check">
                <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                       @checked(old('is_active', true))>
                <label for="is_active" class="form-check-label">{{ __('partners.fields.is_active') }}</label>
            </div>
        </div>
    @endunless

    <div class="col-12">
        <label for="notes" class="form-label">{{ __('partners.fields.notes') }}</label>
        <textarea id="notes" name="notes" rows="2"
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $partner->notes ?? '') }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
