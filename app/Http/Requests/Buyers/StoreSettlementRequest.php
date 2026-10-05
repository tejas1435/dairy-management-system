<?php

declare(strict_types=1);

namespace App\Http\Requests\Buyers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates opening or editing a draft settlement.
 *
 * Authorisation is the controller's, through `BuyerPolicy::manageSettlement()`, which
 * needs the route's buyer to decide — a Form Request cannot express "this buyer must
 * be a Mandali".
 *
 * The statement amount is optional and stays optional: a settlement is routinely
 * opened before the dairy's statement arrives, and an empty field means "not received
 * yet" rather than zero. Finalizing without one is legitimate and produces no
 * adjustment.
 */
class StoreSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],

            // Nullable, not nullable-or-zero: a zero statement is a real claim that
            // nothing is owed, and is treated as such.
            'statement_amount' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:999999999999.99'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'period_start' => __('buyers.settlement.fields.period_start'),
            'period_end' => __('buyers.settlement.fields.period_end'),
            'statement_amount' => __('buyers.settlement.fields.statement_amount'),
        ];
    }
}
