<?php

declare(strict_types=1);

namespace App\Services\Buyers;

use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\MilkSale;
use App\Services\Customers\CustomerLedgerService;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * A Mandali's or vendor's account history, one transaction per line.
 *
 * Deliberately a different *presentation* from {@see CustomerLedgerService},
 * and deliberately the same *arithmetic*. A direct customer's statement groups a day
 * into morning and evening because that is how their round works; a Mandali's shows
 * each collection with its fat, SNF and agreed rate, because that is what the dairy's
 * own statement will be compared against. Forcing one shape on both would make one of
 * them useless.
 *
 * What is **not** duplicated is the balance. Every total here comes from
 * {@see BuyerOutstandingService}, which is the one implementation of
 * `sales + adjustments − payments` for every channel. A `MandaliOutstandingService`
 * would be a second copy to keep correct, and the two would diverge in whichever
 * direction nobody checks.
 *
 * The running balance is computed in order, from an opening figure derived at the day
 * before the period starts, so a filtered page still reconciles with the one before
 * it — the same rule the cashbook follows (D29).
 */
class BuyerTradeLedger
{
    public function __construct(private readonly BuyerOutstandingService $outstanding) {}

    /**
     * The statement for a period.
     *
     * @return array{opening: string, rows: Collection<int, object>, closing: string, totals: array<string, string>}
     */
    public function statement(Buyer $buyer, ?string $from = null, ?string $to = null): array
    {
        $opening = $this->openingBalance($buyer, $from);

        $rows = $this->saleRows($buyer, $from, $to)
            ->concat($this->adjustmentRows($buyer, $from, $to))
            ->concat($this->paymentRows($buyer, $from, $to))
            /*
             * Date, then an explicit kind rank, then id. The rank is 0 for a sale,
             * 1 for an adjustment and 2 for a payment, so a day reads as "what was
             * delivered, what was corrected, what was received" — and the running
             * balance never dips before the charge that caused it. Sorting on the
             * kind *label* would order them alphabetically, which is the bug Phase 4
             * found in the customer statement.
             */
            ->sortBy([['date', 'asc'], ['sort', 'asc'], ['id', 'asc']])
            ->values();

        $running = $opening;

        $rows = $rows->map(function (object $row) use (&$running): object {
            $running = bcadd($running, $row->balanceEffect, Quantity::MONEY_SCALE);
            $row->balance = $running;

            return $row;
        });

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'totals' => [
                'milk' => $this->outstanding->milkTotal($buyer, $from, $to),
                'sales' => $this->outstanding->salesTotal($buyer, $from, $to),
                'adjustments' => $this->outstanding->adjustmentsTotal($buyer, $from, $to),
                'payments' => $this->outstanding->paymentsTotal($buyer, $from, $to),
                // The whole balance, not just the period's movement.
                'outstanding' => $this->outstanding->outstandingFor($buyer),
            ],
        ];
    }

    /**
     * What the buyer owed the day before the period opened.
     *
     * Without it, a filtered statement's running balance would start from zero and
     * every line in it would be wrong by the history above.
     */
    public function openingBalance(Buyer $buyer, ?string $from): string
    {
        if ($from === null) {
            return '0.00';
        }

        $upTo = date('Y-m-d', strtotime($from.' -1 day'));

        return $this->outstanding->outstandingFor($buyer, $upTo);
    }

    /** @return Collection<int, object> */
    private function saleRows(Buyer $buyer, ?string $from, ?string $to): Collection
    {
        return MilkSale::query()
            ->active()
            ->forBuyer($buyer->getKey())
            ->betweenDates($from, $to)
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get()
            ->map(fn (MilkSale $sale): object => (object) [
                'kind' => 'sale',
                'sort' => 0,
                'id' => $sale->getKey(),
                'date' => $sale->sale_date,
                'sale' => $sale,
                'shift' => $sale->shift,
                'milkType' => $sale->milk_type,
                'quantity' => Quantity::of($sale->quantity),
                'fat' => $sale->fat_percentage,
                'snf' => $sale->snf_percentage,
                'rate' => Quantity::money($sale->unit_rate),
                'amount' => Quantity::money($sale->amount),
                // A delivery raises what is owed.
                'balanceEffect' => Quantity::money($sale->amount),
                'reference' => $sale->source->label(),
            ]);
    }

    /** @return Collection<int, object> */
    private function adjustmentRows(Buyer $buyer, ?string $from, ?string $to): Collection
    {
        return BuyerBalanceAdjustment::query()
            ->active()
            ->where('buyer_id', $buyer->getKey())
            ->when($from, fn ($q) => $q->whereDate('adjustment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('adjustment_date', '<=', $to))
            ->with('settlement:id,period_start,period_end')
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get()
            ->map(fn (BuyerBalanceAdjustment $adjustment): object => (object) [
                'kind' => 'adjustment',
                'sort' => 1,
                'id' => $adjustment->getKey(),
                'date' => $adjustment->adjustment_date,
                'adjustment' => $adjustment,
                'direction' => $adjustment->direction,
                'amount' => Quantity::money($adjustment->amount),
                // Signed here, from the stored direction. No column holds a negative.
                'balanceEffect' => $adjustment->signedAmount(),
                'reference' => $adjustment->reason,
            ]);
    }

    /** @return Collection<int, object> */
    private function paymentRows(Buyer $buyer, ?string $from, ?string $to): Collection
    {
        return BuyerPayment::query()
            ->active()
            ->where('buyer_id', $buyer->getKey())
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->with(['paymentMethod:id,name', 'account:id,name', 'settlement:id,period_start,period_end'])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get()
            ->map(fn (BuyerPayment $payment): object => (object) [
                'kind' => 'payment',
                'sort' => 2,
                'id' => $payment->getKey(),
                'date' => $payment->payment_date,
                'payment' => $payment,
                'amount' => Quantity::money($payment->amount),
                // A receipt reduces what is owed.
                'balanceEffect' => Quantity::negateMoney($payment->amount),
                'reference' => $payment->reference,
            ]);
    }
}
