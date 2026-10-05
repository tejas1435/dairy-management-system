<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for cancelling a posted financial record.
 *
 * The reason is mandatory. A cancelled transaction that does not say why is an
 * unexplained hole in the books, and the audit trail exists precisely so later
 * readers do not have to guess.
 *
 * Authorisation is left to the route middleware, because the permission differs
 * per record type.
 */
class CancelTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'cancellation_reason' => __('finance.fields.cancellation_reason'),
        ];
    }
}
