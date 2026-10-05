{{-- Flash messages and the validation summary, rendered once per page by the layout. --}}
@if (session('status'))
    <div class="alert alert-success alert-dismissible d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ session('status') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"
                aria-label="{{ __('app.actions.close') }}"></button>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger alert-dismissible d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>{{ session('error') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"
                aria-label="{{ __('app.actions.close') }}"></button>
    </div>
@endif

<x-validation-errors />
