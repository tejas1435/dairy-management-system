<?php

declare(strict_types=1);

namespace App\Services\Buyers;

use App\Enums\SaleSource;
use App\Models\Buyer;
use App\Models\BuyerSettlement;
use App\Models\MilkSale;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a Mandali's milk for a period came to, and whether that period may be settled.
 *
 * Two jobs, both deliberately read-only. Finalization is a write and lives in its own
 * action; this is the arithmetic and the preconditions, so the figures a draft shows
 * on screen are produced by exactly the same code that snapshots them later.
 *
 * ## The expected amount comes from snapshots, never from prices
 *
 * It is the sum of `milk_sales.amount` for the period — and each of those amounts was
 * computed from the rate stored on its own row when it was saved (D41). Re-pricing the
 * period from current rules would give a different answer for a month that has already
 * been agreed, which is the whole thing settlement exists to avoid.
 *
 * ## Only this Mandali's own active sales
 *
 * Scoped to the buyer, so a direct customer's or a vendor's milk can never land in a
 * Mandali settlement, and to active status, so a cancelled delivery is not settled.
 * Scoped to the Mandali source as well, which matters if a Mandali is ever also sold
 * to through another workflow: a settlement settles collections, not everything the
 * buyer happens to owe.
 */
class MandaliSettlementCalculator
{
    /**
     * The period's figures, from the sales as they stand right now.
     *
     * @return array{milk_quantity: string, expected_amount: string, sale_count: int}
     */
    public function calculate(Buyer $buyer, string $from, string $to): array
    {
        $sales = $this->salesQuery($buyer, $from, $to)
            ->selectRaw('COUNT(*) as sale_count, SUM(quantity) as quantity, SUM(amount) as amount')
            ->first();

        return [
            'milk_quantity' => Quantity::of($sales?->quantity),
            'expected_amount' => Quantity::money($sales?->amount),
            'sale_count' => (int) ($sales?->sale_count ?? 0),
        ];
    }

    /**
     * The individual deliveries behind a period, for the statement screen.
     *
     * @return Collection<int, MilkSale>
     */
    public function salesFor(Buyer $buyer, string $from, string $to)
    {
        return $this->salesQuery($buyer, $from, $to)
            ->orderBy('sale_date')
            ->orderBy('shift')
            ->orderBy('milk_type')
            ->get();
    }

    /**
     * statement − expected: positive means the Mandali says the farm is owed more.
     *
     * Null when no statement was given, which is not the same as a zero difference:
     * no statement means there is nothing to disagree with, and no adjustment follows.
     */
    public function difference(?string $statementAmount, string $expectedAmount): ?string
    {
        if ($statementAmount === null || trim($statementAmount) === '') {
            return null;
        }

        return bcsub(
            Quantity::money($statementAmount),
            Quantity::money($expectedAmount),
            Quantity::MONEY_SCALE
        );
    }

    /**
     * Refuses a period that overlaps one already settled.
     *
     * MASTER_SPEC does not address overlapping settlement periods. Left undefined it
     * is a real hazard rather than a gap worth shrugging at: two settlements covering
     * the same day would each include that day's deliveries in their expected amount,
     * so the same milk could be settled — and adjusted, and paid — twice, and the
     * buyer's balance would be wrong by the overlap with nothing in the data to show
     * why.
     *
     * So the conservative rule: **a Mandali may not have two non-cancelled settlements
     * whose periods overlap.** Cancelled ones are ignored, which is what makes a
     * mistaken settlement fixable — cancel it, then settle the period again.
     *
     * Enforced here rather than by a database constraint because MySQL cannot express
     * "no overlapping ranges"; the check runs inside the creating transaction after a
     * locking read, which is the same pattern availability uses (D35).
     *
     * @throws ValidationException
     */
    public function assertNoOverlap(Buyer $buyer, string $from, string $to, ?int $ignoreId = null): void
    {
        $clash = BuyerSettlement::query()
            ->where('buyer_id', $buyer->getKey())
            ->active()
            ->overlapping($from, $to)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'period_start' => __('buyers.errors.settlement_overlaps', [
                    'from' => $clash->period_start->format('d-m-Y'),
                    'to' => $clash->period_end->format('d-m-Y'),
                ]),
            ]);
        }
    }

    /** @throws ValidationException */
    public function assertOrderedPeriod(string $from, string $to): void
    {
        if (strtotime($to) < strtotime($from)) {
            throw ValidationException::withMessages([
                'period_end' => __('buyers.errors.settlement_period_reversed'),
            ]);
        }
    }

    /**
     * @return Builder<MilkSale>
     */
    private function salesQuery(Buyer $buyer, string $from, string $to)
    {
        return MilkSale::query()
            ->active()
            ->fromSource(SaleSource::MandaliDelivery)
            ->where('buyer_id', $buyer->getKey())
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to);
    }
}
