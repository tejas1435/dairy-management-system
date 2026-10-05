{{-- Renders one recorded value, whatever shape it took. --}}
@if (is_null($value))
    <span class="text-body-tertiary">&mdash;</span>
@elseif (is_bool($value))
    {{ $value ? __('app.status.yes') : __('app.status.no') }}
@elseif (is_array($value))
    @if ($value === [])
        <span class="text-body-tertiary">{{ __('app.status.none') }}</span>
    @else
        <span class="font-monospace">{{ implode(', ', array_map('strval', $value)) }}</span>
    @endif
@elseif ($value === '[redacted]')
    <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">
        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>{{ $value }}
    </span>
@else
    {{ $value }}
@endif
