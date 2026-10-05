<?php

declare(strict_types=1);

namespace App\Actions\Settlements;

use App\Actions\Buyers\CreateBuyerBalanceAdjustment;
use App\Enums\BalanceAdjustmentDirection;
use App\Enums\SettlementStatus;
use App\Models\BuyerSettlement;
use App\Services\AuditLogger;
use App\Services\Buyers\MandaliSettlementCalculator;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agrees a settlement: freezes its figures and accounts for any disagreement.
 *
 * The one moment in Phase 5 where a period stops being a live query and becomes a
 * record. Nine steps, all inside one transaction:
 *
 *  1. refuse unless the settlement is a draft;
 *  2. re-query the Mandali's active deliveries for the period — not the draft's
 *     displayed figures, which may be minutes old;
 *  3. total the litres;
 *  4. total the money **from the sales' own stored amounts**, never from current
 *     prices;
 *  5. snapshot both onto the settlement;
 *  6. take the statement amount, if one was given;
 *  7. compute statement − expected, and if it is not zero create **exactly one**
 *     balance adjustment for it;
 *  8. audit the finalization with the figures it established;
 *  9. commit, or leave nothing behind.
 *
 * **No historical rate is rewritten.** That is the specification's explicit
 * instruction (MASTER_SPEC section 23) and the reason an adjustment exists at all: a
 * Mandali paying ₹300 less than the system calculated is a fact about the receivable,
 * not evidence that last month's rate was wrong. Changing the rate would silently
 * alter every figure derived from those deliveries, including reconciliation history
 * and any other settlement that touched them.
 *
 * Finalizing twice is refused outright, and the database refuses a second adjustment
 * for the same settlement independently (a conditional unique index), so a retry that
 * races the status check cannot double the correction.
 */
class FinalizeBuyerSettlement
{
    public function __construct(
        private readonly MandaliSettlementCalculator $calculator,
        private readonly CreateBuyerBalanceAdjustment $adjustments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $statementAmount  overrides what the draft holds, when the
     *                                        statement arrives at finalization time
     */
    public function handle(BuyerSettlement $settlement, ?string $statementAmount = null): BuyerSettlement
    {
        return DB::transaction(function () use ($settlement, $statementAmount): BuyerSettlement {
            /*
             * Re-read under a lock. Two people finalizing the same settlement must
             * not both pass the draft check and both create an adjustment.
             */
            $locked = BuyerSettlement::query()
                ->whereKey($settlement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertFinalizable($locked);

            $mandali = $locked->buyer;

            // Step 2–4: the authoritative figures, from the sales themselves.
            $figures = $this->calculator->calculate(
                $mandali,
                $locked->period_start->toDateString(),
                $locked->period_end->toDateString(),
            );

            $statement = $statementAmount !== null && trim($statementAmount) !== ''
                ? Quantity::money($statementAmount)
                : ($locked->statement_amount !== null ? Quantity::money($locked->statement_amount) : null);

            $difference = $this->calculator->difference($statement, $figures['expected_amount']);

            // Step 5–6: freeze what was agreed.
            $locked->forceFill([
                'milk_quantity' => $figures['milk_quantity'],
                'expected_amount' => $figures['expected_amount'],
                'statement_amount' => $statement,
                'difference' => $difference,
                'status' => SettlementStatus::Finalized->value,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ])->save();

            // Step 7: one adjustment, and only if there is something to adjust.
            $adjustment = $this->recordDifference($locked, $difference);

            // Step 8.
            $this->audit->updated($locked, [
                'status' => SettlementStatus::Draft->value,
            ], [
                'status' => SettlementStatus::Finalized->value,
                'milk_quantity' => $figures['milk_quantity'],
                'expected_amount' => $figures['expected_amount'],
                'statement_amount' => $statement,
                'difference' => $difference,
                'sale_count' => $figures['sale_count'],
                'balance_adjustment_id' => $adjustment?->getKey(),
            ], $this->subject($locked));

            return $locked->refresh();
        });
    }

    /**
     * Creates the single adjustment a difference implies, or none.
     *
     * Three cases, and only one of them writes anything:
     *
     *  - **no statement** — nothing to disagree with, so no adjustment. The amount
     *    due is simply the system's expected figure.
     *  - **statement equals expected** — the difference is exactly zero. No
     *    adjustment, because a zero-rupee correction is noise in a ledger somebody
     *    will later have to read.
     *  - **statement differs** — one adjustment, its direction taken from the sign of
     *    the difference and its amount from the magnitude, carrying the settlement
     *    reference so the two can always be traced to each other.
     */
    private function recordDifference(BuyerSettlement $settlement, ?string $difference)
    {
        if ($difference === null || bccomp($difference, '0.00', Quantity::MONEY_SCALE) === 0) {
            return null;
        }

        $direction = BalanceAdjustmentDirection::forDifference($difference);
        $amount = ltrim($difference, '-');

        return $this->adjustments->handle(
            buyer: $settlement->buyer,
            direction: $direction,
            amount: $amount,
            reason: __('buyers.settlement.adjustment_reason', [
                'from' => $settlement->period_start->format('d-m-Y'),
                'to' => $settlement->period_end->format('d-m-Y'),
            ]),
            date: $settlement->period_end->toDateString(),
            settlement: $settlement,
        );
    }

    /** @throws ValidationException */
    private function assertFinalizable(BuyerSettlement $settlement): void
    {
        if ($settlement->status->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.settlement_cancelled'),
            ]);
        }

        if (! $settlement->status->canTransitionTo(SettlementStatus::Finalized)) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.settlement_already_finalized'),
            ]);
        }
    }

    private function subject(BuyerSettlement $settlement): string
    {
        $settlement->loadMissing('buyer:id,name');

        return sprintf('%s — %s', $settlement->buyer?->name ?? '', $settlement->periodLabel());
    }
}
