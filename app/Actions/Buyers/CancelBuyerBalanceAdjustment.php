<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Enums\TransactionStatus;
use App\Models\BuyerBalanceAdjustment;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a receivable correction.
 *
 * Nothing is deleted. The row keeps its amount, its direction and its reason; its
 * status changes, and because the outstanding calculation filters on active status
 * the balance moves back on its own with no compensating adjustment written. That is
 * the same mechanism cancellation uses for sales and payments, and the reason the
 * project never needs a correction to cancel out a correction.
 *
 * Called on its own, and by settlement cancellation — a withdrawn settlement must not
 * leave its difference standing against the buyer.
 */
class CancelBuyerBalanceAdjustment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(BuyerBalanceAdjustment $adjustment, string $reason): BuyerBalanceAdjustment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('buyers.errors.cancellation_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($adjustment, $reason): BuyerBalanceAdjustment {
            // Re-read under a lock, so two people cancelling the same adjustment do
            // not both pass the status check.
            $locked = BuyerBalanceAdjustment::query()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('buyers.errors.adjustment_already_cancelled'),
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

    private function subject(BuyerBalanceAdjustment $adjustment): string
    {
        $adjustment->loadMissing('buyer:id,name');

        return sprintf(
            '%s — %s — %s',
            $adjustment->buyer?->name ?? '',
            $adjustment->adjustment_date->format('d-m-Y'),
            $adjustment->direction->label(),
        );
    }
}
