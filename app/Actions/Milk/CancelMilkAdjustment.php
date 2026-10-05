<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\AdjustmentDirection;
use App\Enums\TransactionStatus;
use App\Models\MilkAdjustment;
use App\Services\AuditLogger;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an authorised milk adjustment.
 *
 * Nothing is deleted, for the same reason usage is not: an adjustment is a stated
 * exception, and withdrawing it should read as a withdrawal rather than leave no
 * trace that it was ever claimed.
 *
 * Cancelling an **increase** takes milk back out of the available pool, which can
 * leave a shift over-allocated if usage was recorded against that milk. This action
 * refuses in that case and names what has to happen first. The alternative --
 * allowing it and showing a negative remainder -- would create through a cancel
 * button exactly the state the availability rule exists to prevent, and it would
 * do so to records whose own quantities were all perfectly valid.
 */
class CancelMilkAdjustment
{
    public function __construct(
        private readonly CalculateMilkReconciliation $reconciliation,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(MilkAdjustment $adjustment, string $reason): MilkAdjustment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('milk.errors.cancellation_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($adjustment, $reason): MilkAdjustment {
            $locked = MilkAdjustment::query()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('milk.errors.already_cancelled'),
                ]);
            }

            if ($locked->direction === AdjustmentDirection::Increase) {
                $this->assertRemovingItLeavesAllocationIntact($locked);
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->cancelled($locked, $reason, $this->subject($locked));

            return $locked->refresh();
        });
    }

    /**
     * Refuses to withdraw milk that has already been allocated.
     *
     * @throws ValidationException
     */
    private function assertRemovingItLeavesAllocationIntact(MilkAdjustment $adjustment): void
    {
        $current = $this->reconciliation->forShift(
            $adjustment->farm_id,
            $adjustment->adjustment_date->toDateString(),
            $adjustment->shift,
            $adjustment->milk_type,
        );

        if (Quantity::compare($current->remaining, $adjustment->quantity) >= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'cancellation_reason' => __('milk.errors.cancel_would_over_allocate', [
                'quantity' => $this->litres(Quantity::of($adjustment->quantity)),
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
