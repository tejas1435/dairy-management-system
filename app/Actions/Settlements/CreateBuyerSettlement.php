<?php

declare(strict_types=1);

namespace App\Actions\Settlements;

use App\Enums\SettlementStatus;
use App\Models\Buyer;
use App\Models\BuyerSettlement;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Buyers\MandaliSettlementCalculator;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a draft settlement for a Mandali and a period.
 *
 * **A draft has no accounting effect whatsoever.** It changes no balance, creates no
 * adjustment and settles no milk; it is a working document on which somebody records
 * what the Mandali's statement says. That matters because a draft's displayed figures
 * are live — they follow the sales as they are corrected — and anything with an
 * accounting effect must not move like that.
 *
 * Its snapshot columns are therefore left null rather than pre-filled. A zero would
 * read as "no milk in this period" instead of "not established yet", which is the
 * distinction Phase 3 built `productionEntered` for (D36).
 */
class CreateBuyerSettlement
{
    public function __construct(
        private readonly MandaliSettlementCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    public function handle(
        Buyer $mandali,
        string $periodStart,
        string $periodEnd,
        ?string $statementAmount = null,
        ?string $notes = null,
    ): BuyerSettlement {
        $this->assertIsMandaliOfThisBusiness($mandali);
        $this->calculator->assertOrderedPeriod($periodStart, $periodEnd);

        return DB::transaction(function () use ($mandali, $periodStart, $periodEnd, $statementAmount, $notes): BuyerSettlement {
            /*
             * Lock this Mandali's settlements before checking for an overlap, so two
             * people opening the same month at once cannot both pass the check and
             * create two settlements over the same milk.
             */
            $mandali->settlements()->lockForUpdate()->get();

            $this->calculator->assertNoOverlap($mandali, $periodStart, $periodEnd);

            $settlement = BuyerSettlement::create([
                'buyer_id' => $mandali->getKey(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                // Snapshots stay null until finalization establishes them.
                'milk_quantity' => null,
                'expected_amount' => null,
                'statement_amount' => $this->optionalMoney($statementAmount),
                'difference' => null,
                'status' => SettlementStatus::Draft->value,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($settlement, [
                'buyer_id' => $mandali->getKey(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'statement_amount' => $this->optionalMoney($statementAmount),
                'status' => SettlementStatus::Draft->value,
            ], $this->subject($mandali, $settlement));

            return $settlement;
        });
    }

    /**
     * Updates a draft's statement amount and notes.
     *
     * Only a draft. Once finalized, the statement amount is part of what was agreed
     * and has already produced an adjustment, so changing it would require redoing
     * the settlement — which is what cancelling and re-creating is for.
     */
    public function updateDraft(
        BuyerSettlement $settlement,
        ?string $statementAmount,
        ?string $notes,
    ): BuyerSettlement {
        if (! $settlement->isDraft()) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.settlement_not_draft'),
            ]);
        }

        return DB::transaction(function () use ($settlement, $statementAmount, $notes): BuyerSettlement {
            $before = [
                'statement_amount' => $settlement->statement_amount,
                'notes' => $settlement->notes,
            ];

            $settlement->forceFill([
                'statement_amount' => $this->optionalMoney($statementAmount),
                'notes' => $notes,
            ])->save();

            $this->audit->updated($settlement, $before, [
                'statement_amount' => $settlement->statement_amount,
                'notes' => $settlement->notes,
            ], $this->subject($settlement->buyer, $settlement));

            return $settlement->refresh();
        });
    }

    /** @throws ValidationException */
    private function assertIsMandaliOfThisBusiness(Buyer $buyer): void
    {
        if ((int) $buyer->business_id !== (int) $this->context->business()->getKey()) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.wrong_business'),
            ]);
        }

        if (! $buyer->isMandali()) {
            throw ValidationException::withMessages([
                'buyer' => __('buyers.errors.not_a_mandali'),
            ]);
        }
    }

    private function optionalMoney(?string $amount): ?string
    {
        return $amount === null || trim($amount) === '' ? null : Quantity::money($amount);
    }

    private function subject(?Buyer $mandali, BuyerSettlement $settlement): string
    {
        return sprintf('%s — %s', $mandali?->name ?? '', $settlement->periodLabel());
    }
}
