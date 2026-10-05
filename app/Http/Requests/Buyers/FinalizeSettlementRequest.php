<?php

declare(strict_types=1);

namespace App\Http\Requests\Buyers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the statement amount supplied at finalization.
 *
 * Separate from the draft form because finalization is the moment the figure matters:
 * it is compared against the system's expected amount and any difference becomes a
 * balance adjustment that cannot be edited afterwards. Left empty, the draft's own
 * statement amount is used, and if that is empty too there is simply no difference to
 * account for.
 */
class FinalizeSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'statement_amount' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['statement_amount' => __('buyers.settlement.fields.statement_amount')];
    }
}
