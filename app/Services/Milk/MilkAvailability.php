<?php

declare(strict_types=1);

namespace App\Services\Milk;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Support\Milk\MilkReconciliation;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides whether milk may be allocated.
 *
 * MASTER_SPEC section 15: allocation beyond available milk is blocked for normal
 * users, and an authorised user may exceed it only by recording an explicit
 * adjustment with a reason. Phase 3 has one kind of allocation -- internal usage
 * -- but Phases 4 and 5 add customer deliveries, Mandali and vendor sales, and
 * every one of them has to be refused on the same terms.
 *
 * Putting the check here rather than in the usage action is the point. A rule
 * re-implemented per module is a rule that will differ per module, and the
 * difference will be found by a farm distributing milk it never had.
 *
 * **This service never creates an adjustment.** When there is not enough milk it
 * refuses and says so. Making more milk available is a deliberate, audited act
 * performed by someone holding `milk.adjustment.create`, in its own workflow, with
 * a stated reason. Auto-creating one from the allocation form would turn the
 * authorisation requirement into a formality and produce exactly the silent
 * balancing record the specification forbids.
 */
class MilkAvailability
{
    public function __construct(private readonly CalculateMilkReconciliation $reconciliation) {}

    /** Milk still unallocated for a shift, as a decimal string. */
    public function remaining(int $farmId, string $date, Shift $shift, MilkType $milkType): string
    {
        return $this->reconciliation->forShift($farmId, $date, $shift, $milkType)->remaining;
    }

    /**
     * Whether a quantity can be allocated without pushing remaining below zero.
     *
     * @param  string|null  $excludingQuantity  a quantity already counted in the
     *                                          current figures that this
     *                                          allocation replaces, used when
     *                                          re-saving an existing record
     */
    public function canAllocate(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
        ?string $excludingQuantity = null,
    ): bool {
        $remaining = $this->remaining($farmId, $date, $shift, $milkType);

        if ($excludingQuantity !== null) {
            $remaining = Quantity::add($remaining, $excludingQuantity);
        }

        return Quantity::compare($remaining, $quantity) >= 0;
    }

    /**
     * Refuses an allocation that would exceed the available milk.
     *
     * @param  string  $field  the form field the error attaches to
     *
     * @throws ValidationException
     */
    public function assertCanAllocate(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
        string $field = 'quantity',
        ?string $excludingQuantity = null,
    ): void {
        $result = $this->reconciliation->forShift($farmId, $date, $shift, $milkType);

        $remaining = $excludingQuantity !== null
            ? Quantity::add($result->remaining, $excludingQuantity)
            : $result->remaining;

        if (Quantity::compare($remaining, $quantity) >= 0) {
            return;
        }

        /*
         * Two different failures, because they need two different actions from
         * the user. If production was never entered there is nothing to allocate
         * against and the fix is to enter it. If production exists and the milk is
         * simply spoken for, the fix is a smaller quantity -- or an authorised
         * adjustment, if more milk genuinely was available.
         */
        if (! $result->productionEntered) {
            throw ValidationException::withMessages([
                $field => __('milk.errors.production_not_entered_for_allocation', [
                    'shift' => $shift->label(),
                    'type' => $milkType->label(),
                ]),
            ]);
        }

        throw ValidationException::withMessages([
            $field => __('milk.errors.over_allocated', [
                'requested' => $this->litres($quantity),
                'remaining' => $this->litres($remaining),
            ]),
        ]);
    }

    /** The reconciliation behind a decision, for a screen that wants context. */
    public function reconciliationFor(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): MilkReconciliation {
        return $this->reconciliation->forShift($farmId, $date, $shift, $milkType);
    }

    private function litres(string $quantity): string
    {
        return number_format((float) $quantity, Quantity::SCALE).' '.__('milk.litres_short');
    }
}
