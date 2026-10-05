<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Services\Milk\NoMilkSalesRecorded;
use App\Support\Milk\SalesAllocation;

/**
 * Supplies the sales half of the milk reconciliation.
 *
 * MASTER_SPEC section 15 defines allocated milk as sales plus internal usage.
 * Phase 3 implements production, usage, adjustments and the engine; the
 * specialised sale workflows -- Direct Customers in Phase 4, Mandali and vendors
 * in Phase 5 -- own the other half.
 *
 * This interface is the seam between them. Phase 3 binds
 * {@see NoMilkSalesRecorded}, which truthfully reports that no
 * sales subsystem exists. Phase 4 binds an implementation that aggregates
 * `milk_sales` by channel. The reconciliation service, its result object and the
 * reconciliation screen are all written against this contract and do not change
 * when that happens.
 *
 * Why a seam rather than a query the engine will grow later: without one, the
 * only way to add sales is to edit the engine, and the engine is where the
 * "remaining must not go negative" rule lives. Binding a provider keeps that rule
 * untouched by the phase that starts feeding it sales.
 *
 * @see docs/DECISIONS.md D32
 */
interface MilkSalesAllocator
{
    /**
     * Milk sold from one farm, on one date, in one shift, of one milk type.
     */
    public function allocationFor(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): SalesAllocation;

    /**
     * The channel slugs this allocator can report on, so the reconciliation
     * screen can render a row per channel without assuming which exist.
     *
     * @return array<int, string>
     */
    public function channels(): array;
}
