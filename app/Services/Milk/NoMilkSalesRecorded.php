<?php

declare(strict_types=1);

namespace App\Services\Milk;

use App\Contracts\MilkSalesAllocator;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Support\Milk\SalesAllocation;

/**
 * The Phase 3 sales allocator — **no longer bound.**
 *
 * Phase 4 created `milk_sales` and bound {@see RecordedMilkSales} in its place, so
 * nothing in the application resolves this class any more. It is kept because it is
 * the only implementation of the "no subsystem" state that
 * {@see SalesAllocation::noSubsystem()} represents, and because a
 * test that wants reconciliation with the sales half deliberately absent can bind it
 * explicitly. If neither of those stays true it should be deleted.
 *
 * Its original purpose, and what it did:
 *
 * There is no sales subsystem, so nothing has been sold.
 *
 * This is not a stub standing in for missing work, and it is not test data. It is
 * the correct answer while no `milk_sales` table exists: a farm cannot have sold
 * milk through a module that has never run. What makes it honest rather than a
 * convenient zero is that it says so -- `subsystemExists` is false, and the
 * reconciliation screen tells the reader the sales modules are not built yet
 * instead of showing four zeroes they would read as "no sales today".
 *
 * Phase 4 replaces the container binding with an allocator that aggregates real
 * sales by channel. Nothing else changes.
 *
 * @see MilkSalesAllocator
 */
class NoMilkSalesRecorded implements MilkSalesAllocator
{
    public function allocationFor(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): SalesAllocation {
        return SalesAllocation::noSubsystem();
    }

    /**
     * No channels, because none can have sales yet.
     *
     * Deliberately empty rather than the seeded channel slugs: returning
     * `['mandali', 'vendor', 'direct_customer']` here would have the screen draw
     * three rows of 0.000 L, which is exactly the false impression this class
     * exists to avoid.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [];
    }
}
