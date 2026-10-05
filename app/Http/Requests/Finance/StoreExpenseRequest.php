<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Support\MorphMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the expense fields and the shape of the funding split.
 *
 * The *sum* of the split is deliberately not checked here. That invariant lives
 * in AllocateFundingSources, inside the database transaction, so it holds for
 * every caller including later phases rather than only for requests that arrive
 * through this form.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expense.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.source_type' => ['required', Rule::in(MorphMap::fundingSources())],
            'allocations.*.source_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'allocations.*.payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')],
            'allocations.*.reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Drops rows the user added and left blank, so an empty trailing row is not
     * an error they have to hunt for.
     */
    protected function prepareForValidation(): void
    {
        $allocations = collect($this->input('allocations', []))
            ->filter(fn ($row): bool => filled($row['source_id'] ?? null) || filled($row['amount'] ?? null))
            ->values()
            ->all();

        $this->merge(['allocations' => $allocations]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'expense_date' => __('expenses.fields.date'),
            'expense_category_id' => __('expenses.fields.category'),
            'amount' => __('expenses.fields.amount'),
            'description' => __('expenses.fields.description'),
            'allocations' => __('expenses.fields.funding'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'allocations.required' => __('finance.funding.errors.none_provided'),
            'allocations.min' => __('finance.funding.errors.none_provided'),
        ];
    }
}
