<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\TransactionStatus;
use App\Models\MilkUsage;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a recorded internal usage.
 *
 * Nothing is deleted. The row stays exactly as it was, its status changes, and the
 * reconciliation queries stop counting it because they filter on active status --
 * so the milk it held returns to the available pool without any compensating
 * record being written.
 *
 * That is the whole reason usage is cancelled rather than edited or removed: a
 * usage figure somebody may have already distributed milk against should be
 * visibly withdrawn, with a reason, not quietly altered. Correcting a quantity
 * means cancelling and recording the right one, which leaves both in the history.
 */
class CancelMilkUsage
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(MilkUsage $usage, string $reason): MilkUsage
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('milk.errors.cancellation_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($usage, $reason): MilkUsage {
            // Re-read under a lock: two people cancelling the same record must
            // not both pass the status check.
            $locked = MilkUsage::query()->whereKey($usage->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('milk.errors.already_cancelled'),
                ]);
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
