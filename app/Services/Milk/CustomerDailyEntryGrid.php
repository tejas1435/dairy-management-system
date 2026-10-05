<?php

declare(strict_types=1);

namespace App\Services\Milk;

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\MilkSale;
use App\Services\BusinessContext;
use App\Services\Customers\CustomerEligibilityService;
use App\Services\PriceResolver;
use App\Support\Customers\DeliveryEligibility;
use App\Support\Milk\DailyEntryRow;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Customer Daily Entry grid for one date.
 *
 * A dedicated view model rather than a controller assembling arrays, because the
 * screen needs five different things joined per row — eligibility, preferences, the
 * pause, any sale already recorded, and the applicable rate — and doing that in
 * Blade is how a page ends up issuing a query per customer.
 *
 * **The cost is bounded.** Whatever the customer count, the grid is:
 *
 *   1. one query for eligible direct customers with their preferences;
 *   2. one query for the pauses covering the date;
 *   3. one query for every grid sale on the date;
 *   4. two queries priming the price resolver.
 *
 * Nothing in the row loop touches the database. A query-count test holds this to
 * account with one customer and with many (MASTER_SPEC section 70 forbids N+1 here
 * specifically, because this screen loads every customer at once by design).
 */
class CustomerDailyEntryGrid
{
    public function __construct(
        private readonly CustomerEligibilityService $eligibility,
        private readonly PriceResolver $prices,
        private readonly BusinessContext $context,
    ) {}

    /**
     * Every row the grid should draw for a date, in display order.
     *
     * One row per customer and milk type they actually take, so a customer taking
     * both appears twice and a buffalo-only customer has no cow row to mis-fill.
     *
     * @return Collection<int, DailyEntryRow>
     */
    public function rowsFor(string $date, ?int $farmId = null): Collection
    {
        $farmId ??= $this->context->primaryFarmId();

        $eligibilities = $this->eligibility->forDate($date);
        $customers = $eligibilities->map(fn (DeliveryEligibility $e): Buyer => $e->customer);

        $this->prices->primeFor($customers, $date);
        $sales = $this->salesFor($date, $farmId, $customers->map->getKey()->all());

        $rows = collect();

        foreach ($eligibilities as $eligibility) {
            $customer = $eligibility->customer;

            foreach ($eligibility->milkTypes() as $milkType) {
                $resolved = $this->prices->resolve($customer, $milkType, $date);

                $rows->push(new DailyEntryRow(
                    customer: $customer,
                    milkType: $milkType,
                    eligibility: $eligibility,
                    reminder: $eligibility->reminderFor($milkType),
                    sales: $sales[$customer->getKey()][$milkType->value] ?? [],
                    resolvedRate: $resolved->found ? Quantity::money($resolved->rate()) : null,
                ));
            }
        }

        return $rows;
    }

    /**
     * Column totals for the footer: quantity per shift and milk type, plus money.
     *
     * Computed from the saved rows, so what the footer shows on load is what the
     * database holds. The browser recomputes the same figures as the operator types
     * and the server recomputes them again after a save; this is the starting point,
     * not a source of truth.
     *
     * @param  Collection<int, DailyEntryRow>  $rows
     * @return array{shifts: array<string, array<string, string>>, quantity: string, amount: string}
     */
    public function totalsFor(Collection $rows): array
    {
        $shifts = [];

        foreach (Shift::cases() as $shift) {
            foreach (MilkType::cases() as $milkType) {
                $shifts[$shift->value][$milkType->value] = Quantity::sum(
                    $rows->filter(fn (DailyEntryRow $row): bool => $row->milkType === $milkType)
                        ->map(fn (DailyEntryRow $row): string => $row->savedQuantity($shift) ?? Quantity::ZERO)
                );
            }
        }

        $amount = '0.00';

        foreach ($rows as $row) {
            $amount = bcadd($amount, $row->amount(), Quantity::MONEY_SCALE);
        }

        return [
            'shifts' => $shifts,
            'quantity' => Quantity::sum($rows->map(fn (DailyEntryRow $row): string => $row->total())),
            'amount' => $amount,
        ];
    }

    /**
     * The quantities Copy Previous Day offers, keyed by buyer and milk type.
     *
     * Read-only by construction: it returns figures for the browser to put in the
     * form and writes nothing. Only **active** grid sales from exactly the previous
     * calendar date are offered — a cancelled sale yesterday means the delivery was
     * withdrawn, and copying it forward would silently resurrect it.
     *
     * Rates and amounts are deliberately not returned. The copied quantity is priced
     * by the **selected** date when it is saved, which is the only date that can be
     * right; carrying yesterday's rate across a price change would bill today at
     * yesterday's price.
     *
     * Rows the selected date cannot accept — paused, archived, not yet started, or
     * no longer taking that milk type — are left out entirely, so the copy cannot
     * stage a value the save would refuse.
     *
     * @param  Collection<int, DailyEntryRow>  $rows  the rows valid for the selected date
     * @return array<string, array<string, string>> ["buyerId:milkType"][shift] => quantity
     */
    public function previousDayQuantities(string $date, Collection $rows, ?int $farmId = null): array
    {
        $farmId ??= $this->context->primaryFarmId();

        $editable = $rows->filter(fn (DailyEntryRow $row): bool => $row->isEditable());

        if ($editable->isEmpty()) {
            return [];
        }

        $previous = Carbon::parse($date)->subDay()->toDateString();

        $sales = MilkSale::query()
            ->active()
            ->fromSource(SaleSource::CustomerDailyGrid)
            ->where('farm_id', $farmId)
            ->whereIn('buyer_id', $editable->map(fn (DailyEntryRow $row): int => $row->customer->getKey())->unique()->all())
            ->whereDate('sale_date', $previous)
            ->get();

        $copied = [];

        foreach ($editable as $row) {
            foreach (Shift::cases() as $shift) {
                // Per cell, not per row: a row whose morning is correctable but whose
                // evening cannot be priced must not have a value staged into the
                // evening, because saving it would be refused.
                if (! $row->isEditableFor($shift)) {
                    continue;
                }

                $sale = $sales->first(fn (MilkSale $s): bool => $s->buyer_id === $row->customer->getKey()
                    && $s->milk_type === $row->milkType
                    && $s->shift === $shift);

                if ($sale !== null) {
                    $copied[$row->key()][$shift->value] = Quantity::of($sale->quantity);
                }
            }
        }

        return $copied;
    }

    /**
     * Grid sales on a date, indexed by buyer, milk type and shift.
     *
     * Cancelled rows are included: the grid needs to know a row exists so it reuses
     * its identity and its rate snapshot rather than creating a second one.
     *
     * @param  array<int, int>  $buyerIds
     * @return array<int, array<string, array<string, MilkSale>>>
     */
    private function salesFor(string $date, int $farmId, array $buyerIds): array
    {
        if ($buyerIds === []) {
            return [];
        }

        $indexed = [];

        foreach (MilkSale::query()
            ->fromSource(SaleSource::CustomerDailyGrid)
            ->where('farm_id', $farmId)
            ->whereIn('buyer_id', $buyerIds)
            ->whereDate('sale_date', $date)
            ->get() as $sale) {
            $indexed[$sale->buyer_id][$sale->milk_type->value][$sale->shift->value] = $sale;
        }

        return $indexed;
    }
}
