@props(['title', 'subtitle' => null, 'back' => null])

@section('page-title', $title)
@if ($subtitle)
    @section('page-context', $subtitle)
@endif

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div class="min-w-0">
        <h2 class="h5 mb-1">{{ $title }}</h2>
        @if ($subtitle)
            <p class="text-body-secondary small mb-0">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty() || $back)
        <div class="d-flex flex-wrap gap-2">
            {{ $slot }}
            @if ($back)
                <a href="{{ $back }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('app.actions.back') }}
                </a>
            @endif
        </div>
    @endif
</div>
