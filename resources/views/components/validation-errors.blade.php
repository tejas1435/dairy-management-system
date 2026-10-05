{{--
    Validation summary.

    Individual fields also show their own message; this block exists so a long
    form does not require hunting, and so errors that belong to no single field
    are still visible.
--}}
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <div class="fw-semibold mb-2">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            {{ __('app.errors.validation_title') }}
        </div>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
