<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Enums\BalanceAdjustmentDirection;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerSettlement;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Buyers\BuyerOutstandingService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an explicit correction to what a buyer owes.
 *
 * The specification's answer to a Mandali statement that disagrees with the system
 * (MASTER_SPEC section 23): do not silently modify historical milk rates — record an
 * adjustment with an amount, a reason, a settlement reference, a creator and a
 * timestamp.
 *
 * It moves money owed and **nothing else**. No litre changes, no reconciliation
 * figure changes, no sale is touched, and no financial account is credited or
 * debited: the cash is a separate `BuyerPayment` if and when it arrives.
 *
 * In Pass 1 the only caller is settlement finalization. A manual adjustment screen is
 * a reasonable future addition, and it would need its own permission; this action is
 * written to serve both so that the rules live in one place when it arrives.
 */
class CreateBuyerBalanceAdjustment
{
    public function __construct(
        private readonly BuyerOutstandingService $outstanding,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /**
     * @param  string  $amount  always positive; the direction carries the sign
     */
    public function handle(
        Buyer $buyer,
        BalanceAdjustmentDirection $direction,
        string $amount,
        string $reason,
        string $date,
        ?BuyerSettlement $settlement = null,
    ): BuyerBalanceAdjustment {
        $amount = Quantity::money($amount);

        $this->assertBuyerBelongsToBusiness($buyer);
        $this->assertAmountIsPositive($amount);
        $this->assertReasonGiven($reason);
        $this->assertSettlementBelongsToBuyer($buyer, $settlement);

        return DB::transaction(function () use ($buyer, $direction, $amount, $reason, $date, $settlement): BuyerBalanceAdjustment {
            /*
             * Lock the buyer's existing adjustments and payments before measuring the
             * balance, for the same reason the payment action does: two decreases
             * recorded at once could each see enough owed to be allowed, and together
             * push the buyer into credit.
             */
            $buyer->balanceAdjustments()->active()->lockForUpdate()->get();

            $this->assertDoesNotCreateCreditBalance($buyer, $direction, $amount);

            $adjustment = BuyerBalanceAdjustment::create([
                'buyer_id' => $buyer->getKey(),
                'buyer_settlement_id' => $settlement?->getKey(),
                'adjustment_date' => $date,
                'direction' => $direction->value,
                'amount' => $amount,
                'reason' => $reason,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($adjustment, [
                'buyer_id' => $buyer->getKey(),
                'buyer_settlement_id' => $settlement?->getKey(),
                'adjustment_date' => $date,
                'direction' => $direction->value,
                'amount' => $amount,
                'reason' => $reason,
            ], $this->subject($buyer, $adjustment));

            return $adjustment;
        });
    }

    /** @throws ValidationException */
    private function assertAmountIsPositive(string $amount): void
    {
        if (bccomp($amount, '0.00', Quantity::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('buyers.errors.adjustment_amount_positive'),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertReasonGiven(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('buyers.errors.adjustment_reason_required'),
            ]);
        }
    }

    /**
     * Refuses a decrease that would leave the buyer in credit.
     *
     * MASTER_SPEC section 27 permits a negative balance only through an explicit
     * authorised workflow, and no such workflow exists. Allowing it here would
     * invent one by accident: the buyer's ledger would show them in credit for
     * reasons nobody designed, and the figure would then have to be interpreted
     * differently from every other balance in the system.
     *
     * An increase is never refused — somebody owing more is always representable.
     *
     * @throws ValidationException
     */
    private function assertDoesNotCreateCreditBalance(
        Buyer $buyer,
        BalanceAdjustmentDirection $direction,
        string $amount,
    ): void {
        if ($direction === BalanceAdjustmentDirection::Increase) {
            return;
        }

        $outstanding = $this->outstanding->outstandingFor($buyer);

        if (bccomp($amount, $outstanding, Quantity::MONEY_SCALE) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('buyers.errors.adjustment_exceeds_outstanding', [
                    'amount' => $this->money($amount),
                    'outstanding' => $this->money($outstanding),
                ]),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertSettlementBelongsToBuyer(Buyer $buyer, ?BuyerSettlement $settlement): void
    {
        if ($settlement !== null && (int) $settlement->buyer_id !== (int) $buyer->getKey()) {
            throw ValidationException::withMessages([
                'buyer_settlement_id' => __('buyers.errors.settlement_wrong_buyer'),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertBuyerBelongsToBusiness(Buyer $buyer): void
    {
        if ((int) $buyer->business_id !== (int) $this->context->business()->getKey()) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.wrong_business'),
            ]);
        }
    }

    private function subject(Buyer $buyer, BuyerBalanceAdjustment $adjustment): string
    {
        return sprintf(
            '%s — %s — %s',
            $buyer->name,
            $adjustment->adjustment_date->format('d-m-Y'),
            $adjustment->direction->label(),
        );
    }

    private function money(string $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }
}
