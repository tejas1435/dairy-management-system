<?php

declare(strict_types=1);

namespace App\Services\Milk;

use App\Contracts\MilkSalesAllocator;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Support\Milk\MilkReconciliation;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * The Phase 3 reconciliation engine (MASTER_SPEC section 15).
 *
 *     available = production + authorised adjustments
 *     allocated = sales + internal usage
 *     remaining = available - allocated
 *
 * Calculated per farm, date, shift and milk type. Three things about how:
 *
 *  1. **Production comes from a column, not a row.** `milk_productions` holds one
 *     row per farm, date and shift with both milk types on it, so cow
 *     reconciliation reads `cow_milk_quantity` from that row. There is no second
 *     production row to find, and none is created to make the arithmetic tidier
 *     (docs/DECISIONS.md D30).
 *
 *  2. **Missing production is a state, not a zero.** No row means nobody has
 *     entered the shift, which the result records as `productionEntered = false`
 *     alongside a production figure of 0.000. The two are not interchangeable and
 *     this service never lets them merge.
 *
 *  3. **Sales arrive through a seam.** Allocated milk includes sales, and the
 *     sale workflows are Phases 4 and 5. A {@see MilkSalesAllocator} supplies
 *     that half; in Phase 3 the bound implementation reports honestly that no
 *     sales subsystem exists. The formula is already complete.
 *
 * Cancelled usage and cancelled adjustments are excluded by every query here.
 * A cancelled record stays in the table and the audit trail and stops affecting
 * milk allocation, which is exactly what MASTER_SPEC section 60 requires.
 */
class CalculateMilkReconciliation
{
    public function __construct(private readonly MilkSalesAllocator $sales) {}

    public function forShift(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): MilkReconciliation {
        $production = MilkProduction::query()
            ->forShift($farmId, $date, $shift)
            ->first();

        [$increase, $decrease] = $this->adjustmentTotals($farmId, $date, $shift, $milkType);
        $adjustmentTotal = Quantity::sub($increase, $decrease);

        /*
         * Production contributes 0.000 when the row is absent. The zero is
         * arithmetically right -- there is no milk to count -- but it travels
         * with productionEntered = false so no caller can mistake it for a
         * recorded zero.
         */
        $productionQuantity = $production?->quantityFor($milkType) ?? Quantity::ZERO;

        $available = Quantity::add($productionQuantity, $adjustmentTotal);

        $salesAllocation = $this->sales->allocationFor($farmId, $date, $shift, $milkType);
        $usageByType = $this->usageByType($farmId, $date, $shift, $milkType);
        $usageTotal = Quantity::sum($usageByType);

        $allocated = Quantity::add($salesAllocation->total, $usageTotal);

        return new MilkReconciliation(
            farmId: $farmId,
            date: $date,
            shift: $shift,
            milkType: $milkType,
            productionEntered: $production !== null,
            production: $productionQuantity,
            adjustmentTotal: $adjustmentTotal,
            increaseTotal: $increase,
            decreaseTotal: $decrease,
            available: $available,
            sales: $salesAllocation,
            usageTotal: $usageTotal,
            usageByType: $usageByType,
            allocated: $allocated,
            remaining: Quantity::sub($available, $allocated),
        );
    }

    /**
     * Every milk type for one shift, keyed by milk type value.
     *
     * @return array<string, MilkReconciliation>
     */
    public function forShiftAllTypes(int $farmId, string $date, Shift $shift): array
    {
        $results = [];

        foreach (MilkType::cases() as $milkType) {
            $results[$milkType->value] = $this->forShift($farmId, $date, $shift, $milkType);
        }

        return $results;
    }

    /**
     * Every shift and milk type for one date, keyed by shift then milk type.
     *
     * @return array<string, array<string, MilkReconciliation>>
     */
    public function forDate(int $farmId, string $date): array
    {
        $results = [];

        foreach (Shift::cases() as $shift) {
            $results[$shift->value] = $this->forShiftAllTypes($farmId, $date, $shift);
        }

        return $results;
    }

    /**
     * Increase and decrease totals for a shift, kept apart so the UI can show
     * both rather than only their net effect.
     *
     * Summed in SQL and then normalised through Quantity, so a long day costs one
     * query and no float ever holds a litre value.
     *
     * @return array{0: string, 1: string}
     */
    private function adjustmentTotals(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): array {
        $totals = MilkAdjustment::query()
            ->active()
            ->forShift($farmId, $date, $shift, $milkType)
            ->selectRaw('direction, SUM(quantity) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return [
            Quantity::of($totals['increase'] ?? null),
            Quantity::of($totals['decrease'] ?? null),
        ];
    }

    /**
     * Internal usage for a shift, broken down by usage type.
     *
     * Every type is present, including the ones with no usage, so the
     * reconciliation screen renders a stable set of rows rather than a list that
     * changes shape with the data.
     *
     * @return array<string, string>
     */
    private function usageByType(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): array {
        /** @var Collection<string, mixed> $totals */
        $totals = MilkUsage::query()
            ->active()
            ->forShift($farmId, $date, $shift, $milkType)
            ->selectRaw('usage_type, SUM(quantity) as total')
            ->groupBy('usage_type')
            ->pluck('total', 'usage_type');

        $byType = [];

        foreach (MilkUsageType::cases() as $type) {
            $byType[$type->value] = Quantity::of($totals[$type->value] ?? null);
        }

        return $byType;
    }
}
