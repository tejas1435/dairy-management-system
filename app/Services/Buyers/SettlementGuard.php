<?php

declare(strict_types=1);

namespace App\Services\Buyers;

use App\Models\BuyerSettlement;
use App\Models\MilkSale;
use Illuminate\Validation\ValidationException;

/**
 * Protects a finalized settlement from being quietly contradicted.
 *
 * A finalized settlement carries snapshots: the milk quantity, the expected amount,
 * the statement amount and the difference that became a balance adjustment. Once a
 * period has been agreed, correcting a delivery inside it has two possible outcomes
 * and both are bad if they happen silently.
 *
 * **Recalculate the settlement** and an agreed figure changes behind the user's back,
 * while the adjustment already posted against the old figure now reconciles with
 * nothing. **Leave it alone** and the settlement claims a quantity and an amount that
 * its own underlying sales no longer add up to — a statement that cannot be
 * reproduced from the records it summarises, which is exactly what a reader will try
 * to do when they question it.
 *
 * So Phase 5 takes the conservative third option: **refuse the correction.** A sale
 * whose date falls inside a finalized, non-cancelled settlement cannot be changed or
 * cancelled until that settlement is itself cancelled. The order of operations is
 * then explicit and auditable — withdraw the settlement, fix the delivery, settle the
 * period again — rather than implicit and lossy.
 *
 * This costs the user a step they might not expect, and says so in the message. The
 * alternative costs them a settlement they cannot explain.
 *
 * Only Mandali sales are affected, because only a Mandali has settlements; a direct
 * customer's deliveries are never covered by one and behave exactly as they did in
 * Phase 4.
 */
class SettlementGuard
{
    /**
     * The finalized settlement covering a sale, if there is one.
     *
     * Matched on the sale's own date, which is the business date the settlement period
     * is expressed in — not on when the row was created.
     */
    public function settlementCovering(MilkSale $sale): ?BuyerSettlement
    {
        return BuyerSettlement::query()
            ->where('buyer_id', $sale->buyer_id)
            ->finalized()
            ->coveringDate($sale->sale_date->toDateString())
            ->first();
    }

    /**
     * Refuses a change to a sale that a finalized settlement has already accounted
     * for.
     *
     * @throws ValidationException
     */
    public function assertNotSettled(MilkSale $sale, string $field = 'quantity'): void
    {
        $settlement = $this->settlementCovering($sale);

        if ($settlement === null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('buyers.errors.sale_covered_by_settlement', [
                'from' => $settlement->period_start->format('d-m-Y'),
                'to' => $settlement->period_end->format('d-m-Y'),
            ]),
        ]);
    }

    /**
     * Whether a new sale may be recorded on a date already settled.
     *
     * Refused for the same reason as a correction: a delivery added to a settled
     * period would be milk the settlement did not count and nobody has agreed to pay
     * for, sitting inside a period whose figures are closed.
     *
     * @throws ValidationException
     */
    public function assertDateNotSettled(int $buyerId, string $date, string $field = 'sale_date'): void
    {
        $settlement = BuyerSettlement::query()
            ->where('buyer_id', $buyerId)
            ->finalized()
            ->coveringDate($date)
            ->first();

        if ($settlement === null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('buyers.errors.date_covered_by_settlement', [
                'from' => $settlement->period_start->format('d-m-Y'),
                'to' => $settlement->period_end->format('d-m-Y'),
            ]),
        ]);
    }
}
