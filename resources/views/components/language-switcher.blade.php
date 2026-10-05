{{--
    Language switcher.

    Available to guests as well, so the sign-in and password-reset screens can be
    read by someone who has not signed in. For a signed-in user the choice is
    persisted on their account; for a guest it lives in the session.

    Each language is written in its own script rather than translated, because
    someone looking for their language recognises it that way.
--}}
@props(['variant' => 'dropdown'])

@php
    $current = \App\Enums\Locale::tryFromOrDefault(app()->getLocale());
@endphp

<div class="dropdown">
    <button class="btn btn-sm btn-light border dropdown-toggle" type="button"
            data-bs-toggle="dropdown" aria-expanded="false"
            aria-label="{{ __('nav.language') }}">
        <i class="bi bi-translate" aria-hidden="true"></i>
        <span class="d-none d-sm-inline ms-1">{{ $current->nativeName() }}</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        @foreach (\App\Enums\Locale::cases() as $locale)
            <li>
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale->value }}">
                    <button type="submit"
                            class="dropdown-item d-flex align-items-center justify-content-between gap-3"
                            @if ($locale === $current) aria-current="true" @endif>
                        <span>{{ $locale->nativeName() }}</span>
                        @if ($locale === $current)
                            <i class="bi bi-check2 text-success" aria-hidden="true"></i>
                        @endif
                    </button>
                </form>
            </li>
        @endforeach
    </ul>
</div>
