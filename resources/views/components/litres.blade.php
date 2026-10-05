@props(['quantity' => null, 'muted' => false, 'unit' => true, 'strong' => false])

{{--
    Renders a milk quantity in litres.

    Formatting only. The value arrives already computed as a decimal string; this
    component never adds, subtracts or rounds anything that matters. Blade displays
    quantities, it does not decide them.

    A null quantity is NOT zero. It means the figure has not been recorded, and it
    renders as an em-dash so the reader can tell the difference — the same
    distinction the reconciliation engine keeps in `productionEntered`.
--}}
@php
    $recorded = $quantity !== null && $quantity !== '';
    $value = $recorded ? (float) $quantity : null;
    $negative = $recorded && $value < 0;
@endphp

<span {{ $attributes->merge([
    'class' => 'text-nowrap font-monospace'
        .($negative ? ' text-danger' : '')
        .($muted ? ' text-body-secondary' : '')
        .($strong ? ' fw-semibold' : ''),
]) }}>
    @if ($recorded)
        {{ number_format($value, 3) }}@if ($unit)&nbsp;{{ __('milk.litres_short') }}@endif
    @else
        <span class="text-body-secondary" aria-label="{{ __('milk.not_entered_short') }}">&mdash;</span>
    @endif
</span>
