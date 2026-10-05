<?php

declare(strict_types=1);

namespace App\Support\Milk;

use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Support\Quantity;

/**
 * The reconciliation of one farm, date, shift and milk type
 * (MASTER_SPEC section 15).
 *
 *     available = production + authorised adjustments
 *     allocated = sales + internal usage
 *     remaining = available - allocated
 *
 * A value object rather than an array, for two reasons. The first is that
 * `$result['remaining']` gives a caller no help; `$result->remaining` and
 * `$result->wouldOverAllocate()` do. The second matters more:
 * **`productionEntered` must not be something a caller can forget to look at.**
 *
 * That flag is the difference between two situations that both have 0.000 litres
 * of production and mean completely different things:
 *
 *   - somebody recorded the shift and stated it produced nothing;
 *   - nobody has recorded the shift at all.
 *
 * The specification requires the second to display "Production not entered"
 * rather than a zero. Keeping the flag on the result, beside the quantity it
 * qualifies, is what lets the Phase 4 sale validation refuse to allocate against
 * a shift nobody has entered yet -- a distinction that would be gone forever if
 * the engine returned a bare number.
 *
 * Every quantity is a decimal string at three places, never a float.
 */
final readonly class MilkReconciliation
{
    /**
     * @param  array<string, string>  $usageByType  usage type value => quantity
     */
    public function __construct(
        public int $farmId,
        public string $date,
        public Shift $shift,
        public MilkType $milkType,
        public bool $productionEntered,
        public string $production,
        public string $adjustmentTotal,
        public string $increaseTotal,
        public string $decreaseTotal,
        public string $available,
        public SalesAllocation $sales,
        public string $usageTotal,
        public array $usageByType,
        public string $allocated,
        public string $remaining,
    ) {}

    /** Usage of one type, zero when there was none. */
    public function usageFor(MilkUsageType $type): string
    {
        return $this->usageByType[$type->value] ?? Quantity::ZERO;
    }

    /** Whether this type contributed any usage, so a row is worth drawing. */
    public function hasUsageOf(MilkUsageType $type): bool
    {
        return ! Quantity::isZero($this->usageFor($type));
    }

    /**
     * Whether any adjustment applies, in either direction.
     *
     * Checks the two directions rather than their net total: an increase of 2.000
     * and a decrease of 2.000 net to zero, and a screen that hid them would be
     * concealing the two exceptions somebody recorded.
     */
    public function hasAdjustments(): bool
    {
        return ! Quantity::isZero($this->increaseTotal)
            || ! Quantity::isZero($this->decreaseTotal);
    }

    /**
     * Whether more milk has been allocated than was available.
     *
     * Reachable only through the authorised adjustment flow or by cancelling an
     * adjustment that allocations depended on. Ordinary allocation is refused
     * before it can get here.
     */
    public function isOverAllocated(): bool
    {
        return Quantity::isNegative($this->remaining);
    }

    public function isFullyAllocated(): bool
    {
        return Quantity::isZero($this->remaining);
    }

    /** Whether allocating a further quantity would push remaining below zero. */
    public function wouldOverAllocate(string $additionalQuantity): bool
    {
        return Quantity::isNegative(Quantity::sub($this->remaining, $additionalQuantity));
    }

    /**
     * Whether anything at all has been recorded for this shift and milk type.
     *
     * Used to tell an untouched shift from a worked one, so a list can show it as
     * empty rather than as a set of zeroes.
     */
    public function isUntouched(): bool
    {
        return ! $this->productionEntered
            && Quantity::isZero($this->adjustmentTotal)
            && Quantity::isZero($this->allocated);
    }

    /**
     * Bootstrap contextual colour for the remaining figure.
     *
     * Negative is a genuine problem and is coloured as one; exactly zero is a
     * fully-distributed shift, which is normal and good.
     */
    public function remainingBadge(): string
    {
        return match (true) {
            $this->isOverAllocated() => 'text-danger-emphasis bg-danger-subtle border border-danger-subtle',
            $this->isFullyAllocated() => 'text-success-emphasis bg-success-subtle border border-success-subtle',
            default => 'text-primary-emphasis bg-primary-subtle border border-primary-subtle',
        };
    }
}
