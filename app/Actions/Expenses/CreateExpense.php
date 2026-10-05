<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Actions\Finance\AllocateFundingSources;
use App\Enums\AuditAction;
use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records an expense together with who paid for it.
 *
 * One transaction covers the expense, every funding allocation, the resulting
 * account debits and the audit records. If the ledger posting fails, the
 * expense does not exist either: a half-recorded expense is worse than none,
 * because the books would balance while the paperwork disagrees.
 */
class CreateExpense
{
    public function __construct(
        private readonly AllocateFundingSources $allocate,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $allocations
     */
    public function handle(array $attributes, array $allocations): Expense
    {
        return DB::transaction(function () use ($attributes, $allocations): Expense {
            $expense = Expense::create([
                'business_id' => $this->context->business()->id,
                'farm_id' => $attributes['farm_id'] ?? $this->context->primaryFarmOrNull()?->id,
                'expense_category_id' => $attributes['expense_category_id'],
                'expense_date' => $attributes['expense_date'],
                'amount' => $attributes['amount'],
                'description' => $attributes['description'],
                'payee_name' => $attributes['payee_name'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            $created = $this->allocate->handle(
                payable: $expense,
                allocations: $allocations,
                expectedTotal: (string) $expense->amount,
                date: $expense->expense_date->toDateString(),
                description: __('expenses.ledger.funded', ['description' => $expense->description]),
                purpose: 'expense_funding',
            );

            $this->audit->created($expense, [
                'expense_date' => $expense->expense_date->toDateString(),
                'amount' => (string) $expense->amount,
                'description' => $expense->description,
                'expense_category_id' => $expense->expense_category_id,
                'payee_name' => $expense->payee_name,
            ], $expense->description);

            $this->audit->custom(
                AuditAction::Funded,
                $expense,
                [],
                [
                    'sources' => $created->map(fn ($allocation): string => sprintf(
                        '%s#%d %s',
                        $allocation->source_type,
                        $allocation->source_id,
                        $allocation->amount,
                    ))->all(),
                ],
                $expense->description,
            );

            return $expense->refresh();
        });
    }
}
