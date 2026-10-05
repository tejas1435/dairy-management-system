<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\MilkSale;
use App\Services\Buyers\BuyerOutstandingService;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * A customer's ledger, derived from the records that already exist.
 *
 * **There is no customer_ledger table and there should not be one.** Every line is
 * built from a `milk_sales` row or a `buyer_payments` row that is already the
 * system's record of that milk or that money. A ledger table would be a second copy
 * of the same facts, free to drift, with nothing to say which copy is right when it
 * does — the same reasoning that keeps the partner ledger derived
 * (docs/DECISIONS.md D24).
 *
 * The shape MASTER_SPEC section 21 asks for is a row per date with morning and
 * evening quantities side by side, which is a different shape from the storage: the
 * database holds one sale per shift and milk type. This service does that pivot, and
 * it does it **per milk type** so a customer taking both cow and buffalo does not
 * end up with litres of one added to litres of the other under a single rate. A row
 * is therefore a date and a milk type.
 */
class CustomerLedgerService
{
    public function __construct(private readonly BuyerOutstandingService $outstanding) {}

    /**
     * Ledger rows for a customer over a period, oldest first, with a running
     * balance.
     *
     * Ordered oldest-first because a running balance only means anything read
     * downwards. The opening balance is everything before the window, so a
     * date-filtered view still shows a correct balance rather than starting from
     * zero — the same problem the cashbook solves with a brought-forward figure
     * (D29).
     *
     * @return array{
     *     opening: string,
     *     rows: Collection<int, object>,
     *     closing: string,
     *     totals: array{milk: string, sales: string, payments: string}
     * }
     */
    public function statement(Buyer $customer, ?string $from = null, ?string $to = null): array
    {
        $opening = $this->openingBalance($customer, $from);

        $sales = $this->saleRows($customer, $from, $to);
        $payments = $this->paymentRows($customer, $from, $to);

        /*
         * Sorted by date, then by `sort` — which is 0 for a sale and 1 for a payment,
         * so a payment settling the day reads after the deliveries it settles.
         *
         * Deliberately not sorted on `kind`: that is a label, and sorting it
         * alphabetically puts "payment" before "sale", which makes a running balance
         * dip before the charge that caused it.
         */
        $rows = $sales->concat($payments)
            ->sortBy([['date', 'asc'], ['sort', 'asc'], ['milkTypeOrder', 'asc']])
            ->values();

        $running = $opening;

        $rows = $rows->map(function (object $row) use (&$running): object {
            // A sale increases what is owed; a payment reduces it.
            $running = bcadd($running, $row->balanceEffect, 2);
            $row->balance = $running;

            return $row;
        });

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'totals' => [
                'milk' => $this->outstanding->milkTotal($customer, $from, $to),
                'sales' => $this->outstanding->salesTotal($customer, $from, $to),
                'payments' => $this->outstanding->paymentsTotal($customer, $from, $to),
            ],
        ];
    }

    /**
     * The monthly statement figures MASTER_SPEC section 21 shows.
     *
     * Sales and payments are reported separately and never conflated: revenue
     * recorded is not cash received, and the difference between them is the whole
     * point of the outstanding figure (MASTER_SPEC section 25).
     *
     * @return array{from: string, to: string, milk: string, sales: string, payments: string, outstanding_in_period: string, outstanding_total: string}
     */
    public function monthlySummary(Buyer $customer, int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));

        $sales = $this->outstanding->salesTotal($customer, $from, $to);
        $payments = $this->outstanding->paymentsTotal($customer, $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'milk' => $this->outstanding->milkTotal($customer, $from, $to),
            'sales' => $sales,
            'payments' => $payments,
            // What this month alone added to the balance.
            'outstanding_in_period' => Quantity::money(bcsub($sales, $payments, 2)),
            // And what the customer owes in total, which is the figure that matters.
            'outstanding_total' => $this->outstanding->outstandingFor($customer),
        ];
    }

    /**
     * Everything owed before the window opens.
     *
     * Without this, a September-only view of a customer who owed money in August
     * would show a balance that looks settled.
     */
    public function openingBalance(Buyer $customer, ?string $before): string
    {
        if ($before === null) {
            return '0.00';
        }

        $sales = Quantity::money(
            MilkSale::query()->active()
                ->where('buyer_id', $customer->getKey())
                ->whereDate('sale_date', '<', $before)
                ->sum('amount')
        );

        $payments = Quantity::money(
            BuyerPayment::query()->active()
                ->where('buyer_id', $customer->getKey())
                ->whereDate('payment_date', '<', $before)
                ->sum('amount')
        );

        return Quantity::money(bcsub($sales, $payments, 2));
    }

    /**
     * One row per date and milk type, with the shifts pivoted into columns.
     *
     * @return Collection<int, object>
     */
    private function saleRows(Buyer $customer, ?string $from, ?string $to): Collection
    {
        $sales = MilkSale::query()
            ->active()
            ->where('buyer_id', $customer->getKey())
            ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to))
            ->orderBy('sale_date')
            ->orderBy('milk_type')
            ->get();

        /*
         * Grouped by date *and* milk type. Grouping by date alone would put cow and
         * buffalo litres in one cell under one rate, which is arithmetically
         * meaningless the moment the two rates differ.
         */
        return $sales
            ->groupBy(fn (MilkSale $sale): string => $sale->sale_date->toDateString().'|'.$sale->milk_type->value)
            ->map(function (Collection $group): object {
                /** @var MilkSale $first */
                $first = $group->first();

                $morning = $group->filter(fn (MilkSale $s): bool => $s->shift === Shift::Morning);
                $evening = $group->filter(fn (MilkSale $s): bool => $s->shift === Shift::Evening);

                $amount = Quantity::money($group->reduce(
                    fn (string $carry, MilkSale $s): string => bcadd($carry, Quantity::money($s->amount), 2),
                    '0.00'
                ));

                /*
                 * Rates within one date and milk type are normally identical, since
                 * both shifts resolve the same rule. They can differ if a price
                 * period started between them, so the distinct set is carried and the
                 * view shows "mixed" rather than picking one arbitrarily.
                 */
                $rates = $group->map(fn (MilkSale $s): string => Quantity::money($s->unit_rate))
                    ->unique()->values();

                return (object) [
                    'kind' => 'sale',
                    'sort' => 0,
                    // Keeps cow before buffalo within a date, in enum order, so the
                    // rows do not shuffle between page loads.
                    'milkTypeOrder' => array_search($first->milk_type, MilkType::cases(), true),
                    'date' => $first->sale_date,
                    'milkType' => $first->milk_type,
                    'morningQuantity' => Quantity::of($morning->sum('quantity')),
                    'eveningQuantity' => Quantity::of($evening->sum('quantity')),
                    'totalQuantity' => Quantity::of($group->sum('quantity')),
                    'rate' => $rates->count() === 1 ? $rates->first() : null,
                    'rates' => $rates->all(),
                    'amount' => $amount,
                    'balanceEffect' => $amount,
                    'payment' => null,
                    'reference' => null,
                ];
            })
            ->values();
    }

    /** @return Collection<int, object> */
    private function paymentRows(Buyer $customer, ?string $from, ?string $to): Collection
    {
        return BuyerPayment::query()
            ->active()
            ->with(['paymentMethod:id,name', 'account:id,name'])
            ->where('buyer_id', $customer->getKey())
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->orderBy('payment_date')
            ->get()
            ->map(fn (BuyerPayment $payment): object => (object) [
                'kind' => 'payment',
                // Sorts after the day's sales, so a payment settling the day reads
                // in the order it happened.
                'sort' => 1,
                'milkTypeOrder' => 0,
                'date' => $payment->payment_date,
                'milkType' => null,
                'morningQuantity' => null,
                'eveningQuantity' => null,
                'totalQuantity' => null,
                'rate' => null,
                'rates' => [],
                'amount' => null,
                'balanceEffect' => Quantity::negateMoney($payment->amount),
                'payment' => Quantity::money($payment->amount),
                'reference' => $payment->reference
                    ?: $payment->paymentMethod?->name,
            ])
            ->values();
    }

    /** Milk delivered per milk type over a period, for a summary line. */
    public function milkByType(Buyer $customer, ?string $from = null, ?string $to = null): array
    {
        $totals = MilkSale::query()
            ->active()
            ->where('buyer_id', $customer->getKey())
            ->when($from, fn ($q) => $q->whereDate('sale_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sale_date', '<=', $to))
            ->groupBy('milk_type')
            ->selectRaw('milk_type, SUM(quantity) as total, SUM(amount) as amount')
            ->get()
            ->keyBy('milk_type');

        $byType = [];

        foreach (MilkType::cases() as $type) {
            $row = $totals[$type->value] ?? null;

            $byType[$type->value] = [
                'quantity' => Quantity::of($row?->total),
                'amount' => Quantity::money($row?->amount),
            ];
        }

        return $byType;
    }
}
