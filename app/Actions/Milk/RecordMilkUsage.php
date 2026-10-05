<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\MilkUsage;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Milk\MilkAvailability;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records milk consumed internally rather than sold.
 *
 * The availability check is the substance of this action. Internal usage is the
 * first kind of allocation the application supports, so it is the first thing that
 * can take more milk than a shift produced -- and MASTER_SPEC section 15 requires
 * that to be blocked rather than displayed as a negative remainder.
 *
 * The check runs **inside the transaction**, after a lock on the existing usage
 * rows for the shift. Checking before opening a transaction would leave the
 * obvious race: two people each see 2.000 litres remaining, each records 2.000,
 * and the shift ends up 2.000 litres over-allocated with both saves looking
 * legitimate.
 *
 * Nothing here ever creates an adjustment to make room. If more milk genuinely was
 * available, an authorised person records that separately, with a reason, and it
 * is audited (docs/DECISIONS.md D31).
 */
class RecordMilkUsage
{
    public function __construct(
        private readonly MilkAvailability $availability,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    public function handle(
        string $date,
        Shift $shift,
        MilkType $milkType,
        MilkUsageType $usageType,
        string $quantity,
        ?string $notes = null,
        ?int $farmId = null,
    ): MilkUsage {
        $farmId ??= $this->context->primaryFarmId();
        $quantity = Quantity::of($quantity);

        return DB::transaction(function () use (
            $farmId, $date, $shift, $milkType, $usageType, $quantity, $notes
        ): MilkUsage {
            /*
             * Lock the shift's existing usage before measuring what is left, so
             * a concurrent save cannot slip between the check and the insert.
             */
            MilkUsage::query()
                ->active()
                ->forShift($farmId, $date, $shift, $milkType)
                ->lockForUpdate()
                ->get();

            $this->availability->assertCanAllocate(
                farmId: $farmId,
                date: $date,
                shift: $shift,
                milkType: $milkType,
                quantity: $quantity,
            );

            $usage = MilkUsage::create([
                'farm_id' => $farmId,
                'usage_date' => $date,
                'shift' => $shift->value,
                'milk_type' => $milkType->value,
                'usage_type' => $usageType->value,
                'quantity' => $quantity,
                'notes' => $notes,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($usage, [
                'usage_date' => $date,
                'shift' => $shift->value,
                'milk_type' => $milkType->value,
                'usage_type' => $usageType->value,
                'quantity' => $quantity,
            ], $this->subject($usage));

            return $usage;
        });
    }

    private function subject(MilkUsage $usage): string
    {
        return sprintf(
            '%s — %s — %s — %s',
            $usage->usage_date->format('d-m-Y'),
            $usage->shift->label(),
            $usage->milk_type->label(),
            $usage->usage_type->label(),
        );
    }
}
