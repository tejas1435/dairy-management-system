<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\TransactionStatus;
use App\Models\CustomerPause;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a customer pause.
 *
 * Nothing is deleted. The pause stays in the history with its dates and reason, its
 * status changes, and the "is this customer paused" query stops counting it — so the
 * customer becomes deliverable again immediately, with no compensating record.
 *
 * Kept rather than deleted because a pause is part of why a week has no sales. A
 * fortnight of missing deliveries with no pause on record looks like an operational
 * failure; the same fortnight with a withdrawn pause beside it reads correctly.
 *
 * Withdrawing a pause **cannot** invalidate anything already recorded: the pause
 * prevented sales rather than creating them, so there is no allocation depending on
 * it and no safety check to make. That is the opposite of a milk adjustment, where
 * cancelling an increase can leave a shift over-allocated (docs/DECISIONS.md D35).
 */
class CancelCustomerPause
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CustomerPause $pause, string $reason): CustomerPause
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('customers.errors.cancellation_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($pause, $reason): CustomerPause {
            $locked = CustomerPause::query()
                ->whereKey($pause->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('customers.errors.pause_already_cancelled'),
                ]);
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ])->save();

            $locked->loadMissing('buyer:id,name');

            $this->audit->cancelled($locked, $reason, $this->subject($locked));

            return $locked->refresh();
        });
    }

    private function subject(CustomerPause $pause): string
    {
        return sprintf(
            '%s — %s → %s',
            $pause->buyer?->name ?? '',
            $pause->start_date->format('d-m-Y'),
            $pause->end_date?->format('d-m-Y') ?? __('customers.pauses.open_ended'),
        );
    }
}
