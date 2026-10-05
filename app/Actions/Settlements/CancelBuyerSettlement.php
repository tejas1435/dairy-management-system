<?php

declare(strict_types=1);

namespace App\Actions\Settlements;

use App\Actions\Buyers\CancelBuyerBalanceAdjustment;
use App\Enums\SettlementStatus;
use App\Enums\TransactionStatus;
use App\Models\BuyerPayment;
use App\Models\BuyerSettlement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a settlement, and the correction it posted with it.
 *
 * Two records move together and must not come apart: the settlement stops being in
 * force, and its balance adjustment stops affecting the buyer. Leaving the adjustment
 * active would keep a receivable correction standing for a settlement that no longer
 * exists — money owed for a reason the ledger can no longer explain.
 *
 * ## Payments are not withdrawn with it
 *
 * A `BuyerPayment` is a record that **cash actually arrived**. Cancelling a settlement
 * is a statement about an agreement, not about money received, and reversing a real
 * receipt as a side effect of re-doing some paperwork would take cash out of an
 * account that still holds it.
 *
 * So the conservative rule: **a settlement with active linked payments cannot be
 * cancelled.** Whoever is unwinding it has to deal with the receipts first, through
 * the payment cancellation workflow that exists for exactly that and has its own
 * permission and its own ledger reversal. The refusal says how many receipts are in
 * the way, because "cancel those first" is only actionable if you know what they are.
 *
 * ## Cancelled is terminal
 *
 * Nothing moves out of cancelled. A period that needs settling again gets a new
 * settlement, so the history of what was agreed, withdrawn and then agreed differently
 * survives — which is the same reason sales and payments are cancelled rather than
 * edited.
 */
class CancelBuyerSettlement
{
    public function __construct(
        private readonly CancelBuyerBalanceAdjustment $adjustments,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BuyerSettlement $settlement, string $reason): BuyerSettlement
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('buyers.errors.cancellation_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($settlement, $reason): BuyerSettlement {
            $locked = BuyerSettlement::query()
                ->whereKey($settlement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCancellable($locked);

            $locked->forceFill([
                'status' => SettlementStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ])->save();

            /*
             * The adjustment goes with it. Its own cancellation is audited
             * separately, so the trail shows both events and the reason each
             * happened.
             */
            foreach ($locked->adjustments()->where('status', TransactionStatus::Active->value)->get() as $adjustment) {
                $this->adjustments->handle($adjustment, __('buyers.settlement.adjustment_cancelled_reason', [
                    'period' => $locked->periodLabel(),
                ]));
            }

            $this->audit->cancelled($locked, $reason, $this->subject($locked));

            return $locked->refresh();
        });
    }

    /** @throws ValidationException */
    private function assertCancellable(BuyerSettlement $settlement): void
    {
        if ($settlement->status->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.settlement_already_cancelled'),
            ]);
        }

        if (! $settlement->status->canTransitionTo(SettlementStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.settlement_not_cancellable', [
                    'status' => $settlement->status->label(),
                ]),
            ]);
        }

        $linked = BuyerPayment::query()
            ->where('buyer_settlement_id', $settlement->getKey())
            ->where('status', TransactionStatus::Active->value)
            ->count();

        if ($linked > 0) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.settlement_has_payments', ['count' => $linked]),
            ]);
        }
    }

    private function subject(BuyerSettlement $settlement): string
    {
        $settlement->loadMissing('buyer:id,name');

        return sprintf('%s — %s', $settlement->buyer?->name ?? '', $settlement->periodLabel());
    }
}
