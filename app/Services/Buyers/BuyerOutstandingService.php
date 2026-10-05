<?php

declare(strict_types=1);

namespace App\Services\Buyers;

use App\Enums\BalanceAdjustmentDirection;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\MilkSale;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * What a buyer owes, derived from the records that already exist.
 *
 * MASTER_SPEC section 27:
 *
 *     outstanding = sales + receivable adjustments - payments received
 *
 * **There is no stored outstanding column**, on `buyers` or anywhere else, for the
 * same reason there is no stored account balance: a cached total can drift from the
 * transactions it summarises, and when it does there is no way to tell which one is
 * wrong. The figure is computed on read from active sales and active payments.
 *
 * Receivable adjustments were a named zero through Phase 4, because
 * `buyer_balance_adjustments` did not exist yet and inventing a record to make the
 * arithmetic look complete would have been worse than a term that was honestly
 * empty. **Phase 5 filled it in**, and the screens that already displayed the term
 * did not change.
 *
 * **One engine for every channel.** A Mandali, a vendor, a direct customer and a
 * hotel all owe money the same way, so there is no `MandaliOutstandingService` and
 * there never should be: a second copy of this arithmetic is a second copy to keep
 * correct, and the two would diverge in the direction nobody checks. Channel
 * differences are a matter of presentation and permissions, both of which live
 * elsewhere.
 */
class BuyerOutstandingService
{
    /**
     * The full breakdown for one buyer, optionally up to a date.
     *
     * @return array{sales: string, adjustments: string, payments: string, outstanding: string}
     */
    public function breakdownFor(Buyer $buyer, ?string $upTo = null): array
    {
        $sales = $this->salesTotal($buyer, null, $upTo);
        $payments = $this->paymentsTotal($buyer, null, $upTo);
        $adjustments = $this->adjustmentsTotal($buyer, null, $upTo);

        return [
            'sales' => $sales,
            'adjustments' => $adjustments,
            'payments' => $payments,
            'outstanding' => Quantity::money(
                bcsub(bcadd($sales, $adjustments, 2), $payments, 2)
            ),
        ];
    }

    /** What the buyer owes right now, as a decimal string. */
    public function outstandingFor(Buyer $buyer, ?string $upTo = null): string
    {
        return $this->breakdownFor($buyer, $upTo)['outstanding'];
    }

    /**
     * Outstanding for many buyers at once, keyed by buyer id.
     *
     * Two grouped queries rather than one pair per buyer, so the customer list can
     * show a balance column without becoming an N+1.
     *
     * @param  Collection<int, Buyer>|array<int, int>  $buyers
     * @return array<int, string>
     */
    public function outstandingForMany(Collection|array $buyers): array
    {
        $ids = $buyers instanceof Collection
            ? $buyers->pluck('id')->all()
            : $buyers;

        if ($ids === []) {
            return [];
        }

        $sales = MilkSale::query()
            ->active()
            ->whereIn('buyer_id', $ids)
            ->groupBy('buyer_id')
            ->selectRaw('buyer_id, SUM(amount) as total')
            ->pluck('total', 'buyer_id');

        $payments = BuyerPayment::query()
            ->active()
            ->whereIn('buyer_id', $ids)
            ->groupBy('buyer_id')
            ->selectRaw('buyer_id, SUM(amount) as total')
            ->pluck('total', 'buyer_id');

        /*
         * Adjustments need the direction as well as the buyer, so this groups by
         * both and signs them below — still one query, whatever the buyer count.
         */
        $adjustments = [];

        foreach (BuyerBalanceAdjustment::query()
            ->active()
            ->whereIn('buyer_id', $ids)
            ->groupBy('buyer_id', 'direction')
            ->selectRaw('buyer_id, direction, SUM(amount) as total')
            ->get() as $row) {
            /*
             * `direction` arrives already cast to the enum, because this is a model
             * query with a raw select rather than a plain one. Normalising both ways
             * keeps it correct whichever Eloquent hands back.
             */
            $direction = $row->direction instanceof BalanceAdjustmentDirection
                ? $row->direction
                : BalanceAdjustmentDirection::from((string) $row->direction);

            $signed = $direction->sign() < 0
                ? Quantity::negateMoney($row->total)
                : Quantity::money($row->total);

            $adjustments[$row->buyer_id] = bcadd(
                $adjustments[$row->buyer_id] ?? '0.00',
                $signed,
                Quantity::MONEY_SCALE
            );
        }

        $outstanding = [];

        foreach ($ids as $id) {
            $owed = bcadd(
                Quantity::money($sales[$id] ?? null),
                $adjustments[$id] ?? '0.00',
                Quantity::MONEY_SCALE
            );

            $outstanding[$id] = Quantity::money(
                bcsub($owed, Quantity::money($payments[$id] ?? null), Quantity::MONEY_SCALE)
            );
        }

        return $outstanding;
    }

    /** Total active sales to a buyer, over an optional date range. */
    public function salesTotal(Buyer $buyer, ?string $from = null, ?string $to = null): string
    {
        return Quantity::money(
            MilkSale::query()
                ->active()
                ->where('buyer_id', $buyer->getKey())
                ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to))
                ->sum('amount')
        );
    }

    /** Total active payments from a buyer, over an optional date range. */
    public function paymentsTotal(Buyer $buyer, ?string $from = null, ?string $to = null): string
    {
        return Quantity::money(
            BuyerPayment::query()
                ->active()
                ->where('buyer_id', $buyer->getKey())
                ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
                ->sum('amount')
        );
    }

    /**
     * Net receivable adjustments, over an optional date range.
     *
     * Signed on the way out: an increase adds to what is owed and a decrease
     * subtracts, and the sign is applied here from the stored direction rather than
     * read from a column. Summed in SQL by direction — two grouped rows at most —
     * so a buyer with hundreds of corrections costs the same as one with two.
     */
    public function adjustmentsTotal(Buyer $buyer, ?string $from = null, ?string $to = null): string
    {
        $byDirection = BuyerBalanceAdjustment::query()
            ->active()
            ->where('buyer_id', $buyer->getKey())
            ->when($from, fn ($q) => $q->whereDate('adjustment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('adjustment_date', '<=', $to))
            ->groupBy('direction')
            ->selectRaw('direction, SUM(amount) as total')
            ->pluck('total', 'direction');

        $net = '0.00';

        foreach ($byDirection as $direction => $total) {
            $sign = BalanceAdjustmentDirection::from((string) $direction)->sign();
            $amount = Quantity::money($total);

            $net = $sign < 0
                ? bcsub($net, $amount, Quantity::MONEY_SCALE)
                : bcadd($net, $amount, Quantity::MONEY_SCALE);
        }

        return $net;
    }

    /** Total milk delivered, in litres, over an optional date range. */
    public function milkTotal(Buyer $buyer, ?string $from = null, ?string $to = null): string
    {
        return Quantity::of(
            MilkSale::query()
                ->active()
                ->where('buyer_id', $buyer->getKey())
                ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to))
                ->sum('quantity')
        );
    }

    /**
     * Whether a payment of this amount would exceed what the buyer owes.
     *
     * The guard behind the overpayment refusal. Exactly the outstanding amount is
     * allowed; a paisa more is not.
     */
    public function wouldOverpay(Buyer $buyer, string $amount): bool
    {
        return bccomp(Quantity::money($amount), $this->outstandingFor($buyer), 2) > 0;
    }
}
