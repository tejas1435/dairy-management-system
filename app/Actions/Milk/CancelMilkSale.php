<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\TransactionStatus;
use App\Models\MilkSale;
use App\Services\AuditLogger;
use App\Services\Buyers\SettlementGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a milk sale.
 *
 * Nothing is deleted. The row stays exactly as it was — quantity, rate and amount
 * untouched — its status changes, and every query that matters filters on active
 * status, so the milk returns to the shift's available pool and the amount leaves the
 * customer's outstanding without any compensating record being written.
 *
 * That is the whole reason a sale is cancelled rather than deleted or edited: a
 * delivery somebody may have already been billed for should be visibly withdrawn,
 * with a reason, not quietly removed. The customer ledger keeps both the sale and
 * its withdrawal.
 *
 * The daily grid uses this when a quantity is cleared, passing a system reason so
 * the record says why it went (MASTER_SPEC section 20). The actor is still the person
 * who cleared it.
 */
class CancelMilkSale
{
    /** The reason recorded when a grid quantity is intentionally cleared. */
    public const GRID_REMOVAL_REASON = 'removed_from_customer_daily_entry';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettlementGuard $settlements,
    ) {}

    public function handle(MilkSale $sale, string $reason): MilkSale
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('customers.errors.cancellation_reason_required'),
            ]);
        }

        /*
         * A delivery inside a finalized settlement period cannot be withdrawn while
         * that settlement stands: its quantity and amount are part of a figure
         * somebody has agreed and possibly adjusted for. The settlement is cancelled
         * first, which is an explicit, audited act (Phase 5).
         *
         * Only a Mandali ever has settlements, so this is a no-op for every customer
         * sale and the Phase 4 behaviour is unchanged.
         */
        $this->settlements->assertNotSettled($sale, 'status');

        return DB::transaction(function () use ($sale, $reason): MilkSale {
            // Re-read under a lock: two people cancelling the same sale must not
            // both pass the status check.
            $locked = MilkSale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('customers.errors.sale_already_cancelled'),
                ]);
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
                'updated_by' => Auth::id(),
            ])->save();

            $this->audit->cancelled($locked, $reason, $this->subject($locked));

            return $locked->refresh();
        });
    }

    /**
     * Cancels a grid-generated sale because its quantity was cleared.
     *
     * A translated, stable reason rather than free text, so the history says the same
     * thing however the person's locale is set — and so a report can tell a withdrawn
     * delivery apart from a deliberate correction.
     */
    public function becauseRemovedFromGrid(MilkSale $sale): MilkSale
    {
        return $this->handle($sale, __('customers.sale_cancellation.'.self::GRID_REMOVAL_REASON));
    }

    private function subject(MilkSale $sale): string
    {
        $sale->loadMissing('buyer:id,name');

        return sprintf(
            '%s — %s — %s — %s',
            $sale->buyer?->name ?? '',
            $sale->sale_date->format('d-m-Y'),
            $sale->shift->label(),
            $sale->milk_type->label(),
        );
    }
}
