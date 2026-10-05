@php $isEdit = isset($account) && $account !== null; @endphp

<div class="row g-3">
    <div class="col-12 col-md-6">
        <label for="name" class="form-label">{{ __('finance.accounts.fields.name') }} *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $account->name ?? '') }}"
               class="form-control @error('name') is-invalid @enderror" required autofocus>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6">
        <label for="type" class="form-label">{{ __('finance.accounts.fields.type') }} *</label>
        <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
            @foreach ($types as $type)
                <option value="{{ $type->value }}"
                    @selected(old('type', $account->type->value ?? 'cash') === $type->value)>
                    {{ $type->label() }}
                </option>
            @endforeach
        </select>
        @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6">
        <label for="opening_balance" class="form-label">
            {{ __('finance.accounts.fields.opening_balance') }} *
        </label>
        <div class="input-group">
            <span class="input-group-text">&#8377;</span>
            <input type="number" step="0.01" id="opening_balance" name="opening_balance"
                   value="{{ old('opening_balance', $account->opening_balance ?? '0.00') }}"
                   class="form-control font-monospace @error('opening_balance') is-invalid @enderror"
                   required @disabled($openingBalanceLocked ?? false)>
            @error('opening_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($openingBalanceLocked ?? false)
            <div class="form-text">{{ __('finance.accounts.help.opening_locked') }}</div>
        @endif
    </div>

    @unless ($isEdit)
        <div class="col-12 col-md-6 d-flex align-items-end">
            <div class="form-check">
                <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                       @checked(old('is_active', true))>
                <label for="is_active" class="form-check-label">{{ __('finance.accounts.fields.is_active') }}</label>
            </div>
        </div>
    @endunless

    <div class="col-12">
        <label for="notes" class="form-label">{{ __('finance.accounts.fields.notes') }}</label>
        <textarea id="notes" name="notes" rows="2"
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $account->notes ?? '') }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
