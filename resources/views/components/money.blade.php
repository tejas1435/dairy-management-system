@props(['amount' => '0', 'signed' => false, 'muted' => false])

{{--
    Renders a money value.

    Formatting only: the value arrives already computed server-side as a decimal
    string, and is never recalculated here. Blade displays money, it does not
    decide it.
--}}
@php
    $value = (float) $amount;
    $negative = $value < 0;
@endphp

<span {{ $attributes->merge([
    'class' => 'text-nowrap font-monospace'
        .($negative ? ' text-danger' : '')
        .($muted ? ' text-body-secondary' : ''),
]) }}>
    @if ($signed && $value > 0)+@endif&#8377;{{ number_format($value, 2) }}
</span>
