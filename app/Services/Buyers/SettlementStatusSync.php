<?php

declare(strict_types=1);

namespace App\Services\Buyers;

use App\Models\BuyerSettlement;
use App\Services\AuditLogger;

/**
 * Keeps a settlement's stored status equal to what its receipts imply.
 *
 * `Finalized`, `PartiallyPaid` and `Paid` are not three things a user chooses between
 * — they are one fact about how much of the settlement has been received, and
 * {@see BuyerSettlement::derivedStatus()} computes it. This writes that answer back.
 *
 * The status column exists at all because a list of fifty settlements should not have
 * to sum the receipts behind each one to render a badge, and because filtering on
 * status is a normal thing to want. But it is a cache of a derivation, never an
 * independent opinion, so it is recomputed after every event that could change it:
 * a receipt recorded, a receipt withdrawn.
 *
 * That is why cancelling a payment moves a settlement back from Paid to Partially
 * Paid on its own, with nobody editing a status anywhere.
 */
class SettlementStatusSync
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Recomputes and stores one settlement's payment status.
     *
     * A no-op when the status already matches, so an unchanged save writes nothing
     * and audits nothing. Tolerates null, so callers that may or may not have a
     * settlement do not each need the same conditional.
     */
    public function syncStatus(?BuyerSettlement $settlement): ?BuyerSettlement
    {
        if ($settlement === null) {
            return null;
        }

        $settlement->refresh();

        // A draft has no payments and a cancelled settlement is not a payment event;
        // derivedStatus() returns the current status for both.
        $derived = $settlement->derivedStatus();

        if ($derived === $settlement->status) {
            return $settlement;
        }

        $before = ['status' => $settlement->status->value];

        $settlement->forceFill(['status' => $derived->value])->save();

        /*
         * Audited, because "this settlement became Paid" is a business event somebody
         * may later need to date — even though no human typed it.
         */
        $this->audit->updated($settlement, $before, [
            'status' => $derived->value,
            'paid_amount' => $settlement->paidAmount(),
            'amount_due' => $settlement->amountDue(),
        ], $this->subject($settlement));

        return $settlement->refresh();
    }

    private function subject(BuyerSettlement $settlement): string
    {
        $settlement->loadMissing('buyer:id,name');

        return sprintf('%s — %s', $settlement->buyer?->name ?? '', $settlement->periodLabel());
    }
}
