<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits the descriptive fields of a posted expense.
 *
 * Amount, date and funding split are deliberately absent. Those decided the
 * ledger entries that have already been posted, and letting a form change them
 * would leave the expense, its allocations and the account balances describing
 * three different realities. Correcting them means cancelling and re-entering,
 * which leaves both versions in the audit trail (docs/DECISIONS.md D23).
 */
class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expense.update') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')],
            'description' => ['required', 'string', 'max:255'],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'expense_category_id' => __('expenses.fields.category'),
            'description' => __('expenses.fields.description'),
        ];
    }
}
