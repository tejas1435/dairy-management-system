<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\MilkAdjustment;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an authorised correction to the milk available for a shift.
 *
 * This is the exception route, and the only way the available figure can differ
 * from recorded production. Three things make it an exception rather than a
 * convenience:
 *
 *  - **It is never called automatically.** No allocation workflow creates an
 *    adjustment to make room for itself. If there is not enough milk, the
 *    allocation is refused and a person decides what to do about it. An
 *    auto-created adjustment is precisely the "silent balancing record" the
 *    specification forbids.
 *  - **A reason is required**, here and in the database. An adjustment without one
 *    cannot be told apart from a data-entry mistake later.
 *  - **It is always audited**, including the direction and quantity.
 *
 * A decrease may not take away milk that has already been allocated. Letting it
 * would produce a negative remaining figure through an ordinary save, which is the
 * state the whole availability rule exists to prevent; the person is told to
 * withdraw the allocation first.
 */
class RecordMilkAdjustment
{
    public function __construct(
        private readonly CalculateMilkReconciliation $reconciliation,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    public function handle(
        string $date,
        Shift $shift,
        MilkType $milkType,
        AdjustmentDirection $direction,
        string $quantity,
        string $reason,
        ?int $farmId = null,
    ): MilkAdjustment {
        $farmId ??= $this->context->primaryFarmId();
        $quantity = Quantity::of($quantity);

        if (! Quantity::isPositive($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => __('milk.errors.quantity_positive'),
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('milk.errors.adjustment_reason_required'),
            ]);
        }

        return DB::transaction(function () use (
            $farmId, $date, $shift, $milkType, $direction, $quantity, $reason
        ): MilkAdjustment {
            MilkAdjustment::query()
                ->active()
                ->forShift($farmId, $date, $shift, $milkType)
                ->lockForUpdate()
                ->get();

            if ($direction === AdjustmentDirection::Decrease) {
                $this->assertDecreaseLeavesAllocationIntact(
                    $farmId, $date, $shift, $milkType, $quantity
                );
            }

            $adjustment = MilkAdjustment::create([
                'farm_id' => $farmId,
                'adjustment_date' => $date,
                'shift' => $shift->value,
                'milk_type' => $milkType->value,
                'direction' => $direction->value,
                'quantity' => $quantity,
                'reason' => $reason,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            /*
             * The reason is recorded deliberately. It is user-entered prose about
             * a business event, not a credential or a payload field, and the entry
             * is close to worthless without it.
             */
            $this->audit->created($adjustment, [
                'adjustment_date' => $date,
                'shift' => $shift->value,
                'milk_type' => $milkType->value,
                'direction' => $direction->value,
                'quantity' => $quantity,
                'reason' => $reason,
            ], $this->subject($adjustment));

            return $adjustment;
        });
    }

    /**
     * Refuses a decrease that would take away milk already allocated.
     *
     * @throws ValidationException
     */
    private function assertDecreaseLeavesAllocationIntact(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
    ): void {
        $current = $this->reconciliation->forShift($farmId, $date, $shift, $milkType);

        if (Quantity::compare($current->remaining, $quantity) >= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'quantity' => __('milk.errors.decrease_exceeds_remaining', [
                'requested' => $this->litres($quantity),
                'remaining' => $this->litres($current->remaining),
            ]),
        ]);
    }

    private function subject(MilkAdjustment $adjustment): string
    {
        return sprintf(
            '%s — %s — %s — %s',
            $adjustment->adjustment_date->format('d-m-Y'),
            $adjustment->shift->label(),
            $adjustment->milk_type->label(),
            $adjustment->direction->label(),
        );
    }

    private function litres(string $quantity): string
    {
        return number_format((float) $quantity, Quantity::SCALE).' '.__('milk.litres_short');
    }
}
