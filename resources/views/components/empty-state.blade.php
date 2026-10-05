@props(['icon' => 'inbox', 'title' => null, 'description' => null])

<div class="text-center text-body-secondary py-5">
    <i class="bi bi-{{ $icon }} fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
    <p class="mb-1 fw-medium">{{ $title ?? __('app.empty.title') }}</p>
    <p class="mb-0 small">{{ $description ?? __('app.empty.description') }}</p>
    @if ($slot->isNotEmpty())
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
