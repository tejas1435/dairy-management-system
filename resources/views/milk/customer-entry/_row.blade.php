@php
    /**
     * One grid row: one customer, one milk type, two shifts.
     *
     * The `data-entry-*` attributes are the contract with the JavaScript. Rates are
     * carried in integer paise so the browser can total a column without ever
     * parsing a float, and each cell carries its own rate because an existing sale
     * keeps the rate it was recorded at.
     */
    $search = trim(implode(' ', array_filter([
        $row->customer->name,
        $row->customer->area,
        $row->eligibility->deliveryNote,
    ])));
@endphp

<tr data-entry-row
    data-entry-key="{{ $row->key() }}"
    data-buyer-id="{{ $row->customer->getKey() }}"
    data-milk-type="{{ $row->milkType->value }}"
    data-area="{{ $row->customer->area }}"
    data-search="{{ \Illuminate\Support\Str::lower($search) }}"
    data-editable="{{ $row->isEditable() ? '1' : '0' }}"
    @class(['table-warning-subtle' => $row->isPaused()])>

    <th scope="row" class="fw-normal app-entry-sticky-col">
        <div class="fw-medium">{{ $row->customer->name }}</div>
        @if ($row->caption())
            <div class="small text-body-secondary">{{ $row->caption() }}</div>
        @endif
    </th>

    <td>
        <span class="badge text-bg-light border">{{ $row->milkType->label() }}</span>
    </td>

    {{--
        The reminder column. Deliberately rendered as text in its own cell, never as
        a value, a placeholder or a default on the inputs beside it: a placeholder
        that looks like a figure is exactly the confusion MASTER_SPEC section 19
        forbids (docs/DECISIONS.md D39).
    --}}
    <td class="small text-body-secondary">
        @if ($row->reminder?->hasReminder())
            {{ $row->reminder->reminderSummary() }}
        @else
            <span class="fst-italic">{{ __('milk.customer_entry.reminder_none') }}</span>
        @endif
    </td>

    @foreach (\App\Enums\Shift::cases() as $shift)
        @php
            $rate = $row->rateFor($shift);
            $saved = $row->savedQuantity($shift);
            // Per cell: a row can hold a correctable morning sale beside an evening
            // that cannot be priced at all.
            $disabled = ! $row->isEditableFor($shift) || ! $canSave;
        @endphp
        <td class="text-end">
            <label class="visually-hidden" for="qty-{{ $row->key() }}-{{ $shift->value }}">
                {{ $row->customer->name }} — {{ $row->milkType->label() }} — {{ $shift->label() }}
            </label>
            <input type="number"
                   class="form-control form-control-sm text-end app-entry-input"
                   id="qty-{{ $row->key() }}-{{ $shift->value }}"
                   inputmode="decimal"
                   step="0.001"
                   min="0"
                   max="9999999.999"
                   value="{{ $saved }}"
                   data-entry-input
                   data-shift="{{ $shift->value }}"
                   data-saved="{{ $saved }}"
                   data-rate-paise="{{ $rate === null ? '' : bcmul($rate, '100', 0) }}"
                   @disabled($disabled)>
        </td>
    @endforeach

    {{--
        The rate cell. One figure when both shifts share a rate, and one line per
        shift when they do not — which happens whenever a price rule starts covering
        a date that already has a delivery on it, because the recorded sale keeps the
        rate it was saved at (D41/D43). Showing one number for two prices would make
        the row total look like arithmetic nobody could reproduce.
    --}}
    <td class="text-end">
        @if ($row->displayRate() !== null)
            <x-money :amount="$row->displayRate()" muted />
            @if ($row->rateIsSnapshot(\App\Enums\Shift::Morning) || $row->rateIsSnapshot(\App\Enums\Shift::Evening))
                <span class="visually-hidden">{{ __('milk.customer_entry.snapshot_rate') }}</span>
            @endif
        @elseif ($row->hasMixedRates())
            <div class="small lh-sm" data-row-rates>
                @foreach (\App\Enums\Shift::cases() as $shift)
                    @php $shiftRate = $row->rateFor($shift); @endphp
                    <div class="text-nowrap">
                        <span class="text-body-secondary">{{ __('customers.reminders.'.$shift->value.'_short') }}</span>
                        @if ($shiftRate !== null)
                            <x-money :amount="$shiftRate" muted />
                        @else
                            <span class="text-danger-emphasis">{{ __('milk.customer_entry.price_missing') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <span class="visually-hidden">{{ __('milk.customer_entry.mixed_rates') }}</span>
        @else
            <span class="badge text-bg-danger-subtle text-danger-emphasis">
                {{ __('milk.customer_entry.price_missing') }}
            </span>
        @endif
    </td>

    <td class="text-end">
        <x-litres :quantity="$row->total()" :unit="false" data-row-total />
        <div class="small text-body-secondary">
            <x-money :amount="$row->amount()" muted data-row-amount />
        </div>
    </td>

    <td>
        <span class="badge text-bg-secondary-subtle text-secondary-emphasis" data-row-status>
            {{ __('milk.customer_entry.statuses.'.$row->status()) }}
        </span>

        @if ($row->isPaused())
            <div class="small text-body-secondary mt-1">{{ __('milk.customer_entry.paused_help') }}</div>
        @elseif (! $row->hasPrice() && ! $row->hasActiveSale())
            <div class="small text-body-secondary mt-1">
                {{ __('milk.customer_entry.price_missing_help') }}
                @can('settings.manage')
                    <a href="{{ route('settings.milk-prices.index') }}">{{ __('milk.customer_entry.configure_prices') }}</a>
                @endcan
            </div>
        @endif
    </td>
</tr>
