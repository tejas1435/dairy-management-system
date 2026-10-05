<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Services\AuditLogger;
use App\Services\FinancialLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an expense and undoes its financial effects.
 *
 * Nothing is deleted. The expense row and its allocations stay exactly as they
 * were, the status changes, and each account debit gets a matching reversal
 * credit. The account ends where it started while the ledger still shows both
 * the original payment and the correction, which is what makes a cancelled
 * transaction explainable months later.
 */
class CancelExpense
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Expense $expense, string $reason): Expense
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('expenses.errors.reason_required'),
            ]);
        }

        return DB::transaction(function () use ($expense, $reason): Expense {
            /*
             * Re-read under a lock. Two people cancelling the same expense at
             * once must not both pass the status check and post two reversals.
             */
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('expenses.errors.already_cancelled'),
                ]);
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ])->save();

            /*
             * Reverses only the account-funded portions, because only those
             * produced ledger entries. Partner-funded shares stop counting
             * simply by virtue of the expense no longer being active, since the
             * partner ledger derives from active payables.
             */
            $this->ledger->reverseAllFor(
                reference: $locked,
                description: __('expenses.ledger.cancelled', ['description' => $locked->description]),
                date: now()->toDateString(),
            );

            $this->audit->cancelled($locked, $reason, $locked->description);

            return $locked->refresh();
        });
    }
}
