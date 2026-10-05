<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Enums\TransactionStatus;
use App\Models\BuyerPayment;
use App\Services\AuditLogger;
use App\Services\Buyers\SettlementStatusSync;
use App\Services\FinancialLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a recorded payment and undoes its financial effect.
 *
 * Nothing is deleted. The payment row stays exactly as it was, its status changes, and
 * the account credit gets a matching reversing debit. The account ends where it
 * started while the ledger still shows both the original receipt and the correction,
 * which is what makes a withdrawn payment explainable months later — the same
 * treatment an expense cancellation gets (docs/DECISIONS.md D21).
 *
 * The buyer's outstanding rises again by itself, because it is derived from active
 * payments. No compensating record is written to put it back.
 *
 * `reverseAllFor` takes a row lock and skips entries that already have a reversal, so
 * cancelling twice cannot post a second debit even if the status check were somehow
 * passed twice.
 */
class CancelBuyerPayment
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly SettlementStatusSync $settlements,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BuyerPayment $payment, string $reason): BuyerPayment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('customers.errors.cancellation_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($payment, $reason): BuyerPayment {
            // Re-read under a lock: two people cancelling the same payment must not
            // both pass the status check and post two reversals.
            $locked = BuyerPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('customers.errors.payment_already_cancelled'),
                ]);
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ])->save();

            $locked->loadMissing('buyer:id,name');

            $this->ledger->reverseAllFor(
                reference: $locked,
                description: __('customers.ledger.payment_cancelled_for', [
                    'name' => $locked->buyer?->name ?? '',
                ]),
                date: now()->toDateString(),
            );

            $this->audit->cancelled($locked, $reason, $this->subject($locked));

            /*
             * A withdrawn receipt moves its settlement's status back on its own:
             * Paid becomes Partially Paid, or Partially Paid becomes Finalized,
             * because the status is derived from the active receipts rather than set.
             */
            $this->settlements->syncStatus($locked->settlement);

            return $locked->refresh();
        });
    }

    private function subject(BuyerPayment $payment): string
    {
        return sprintf(
            '%s — %s',
            $payment->buyer?->name ?? '',
            $payment->payment_date->format('d-m-Y'),
        );
    }
}
