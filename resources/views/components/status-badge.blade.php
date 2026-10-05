@props(['active' => true, 'activeLabel' => null, 'inactiveLabel' => null])

<span class="badge rounded-pill {{ $active ? 'text-success-emphasis bg-success-subtle border border-success-subtle' : 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle' }}">
    {{ $active
        ? ($activeLabel ?? __('app.status.active'))
        : ($inactiveLabel ?? __('app.status.inactive')) }}
</span>
