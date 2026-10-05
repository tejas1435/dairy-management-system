@php $isEdit = isset($buyer) && $buyer !== null; @endphp

<div class="row g-3">
    <div class="col-12 col-md-6">
        <label for="name" class="form-label">{{ __('buyers.fields.name') }} *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $buyer->name ?? '') }}"
               class="form-control @error('name') is-invalid @enderror" required autofocus>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-6">
        <label for="sales_channel_id" class="form-label">{{ __('buyers.fields.channel') }} *</label>
        <select id="sales_channel_id" name="sales_channel_id"
                class="form-select @error('sales_channel_id') is-invalid @enderror" required>
            <option value="">{{ __('finance.funding.select_source') }}</option>
            @foreach ($channels as $channel)
                <option value="{{ $channel->id }}"
                    @selected((int) old('sales_channel_id', $buyer->sales_channel_id ?? ($selectedChannel ?? 0)) === $channel->id)>
                    {{ $channel->name }}
                </option>
            @endforeach
        </select>
        @error('sales_channel_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">{{ __('buyers.help.permissions') }}</div>
    </div>

    <div class="col-12 col-md-4">
        <label for="mobile" class="form-label">{{ __('buyers.fields.mobile') }}</label>
        <input type="tel" id="mobile" name="mobile" value="{{ old('mobile', $buyer->mobile ?? '') }}"
               class="form-control @error('mobile') is-invalid @enderror" inputmode="tel">
        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-4">
        <label for="email" class="form-label">{{ __('buyers.fields.email') }}</label>
        <input type="email" id="email" name="email" value="{{ old('email', $buyer->email ?? '') }}"
               class="form-control @error('email') is-invalid @enderror" inputmode="email">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-4">
        <label for="area" class="form-label">{{ __('buyers.fields.area') }}</label>
        <input type="text" id="area" name="area" value="{{ old('area', $buyer->area ?? '') }}"
               class="form-control @error('area') is-invalid @enderror">
        @error('area')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-4">
        <label for="payment_cycle" class="form-label">{{ __('buyers.fields.payment_cycle') }}</label>
        <input type="text" id="payment_cycle" name="payment_cycle"
               value="{{ old('payment_cycle', $buyer->payment_cycle ?? '') }}"
               class="form-control @error('payment_cycle') is-invalid @enderror">
        @error('payment_cycle')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 col-md-8">
        <label for="address" class="form-label">{{ __('buyers.fields.address') }}</label>
        <textarea id="address" name="address" rows="2"
                  class="form-control @error('address') is-invalid @enderror">{{ old('address', $buyer->address ?? '') }}</textarea>
        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label for="notes" class="form-label">{{ __('buyers.fields.notes') }}</label>
        <textarea id="notes" name="notes" rows="2"
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $buyer->notes ?? '') }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
