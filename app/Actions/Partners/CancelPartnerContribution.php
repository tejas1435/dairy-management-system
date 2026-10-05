<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\TransactionStatus;
use App\Models\PartnerContribution;
use App\Services\AuditLogger;
use App\Services\FinancialLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a partner contribution and reverses the account credit it made.
 *
 * The contribution row survives with its cancellation metadata, and the ledger
 * keeps both the original credit and the reversing debit, so the account nets
 * to zero without the history being rewritten.
 */
class CancelPartnerContribution
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PartnerContribution $contribution, string $reason): PartnerContribution
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => __('partners.errors.reason_required'),
            ]);
        }

        return DB::transaction(function () use ($contribution, $reason): PartnerContribution {
            $locked = PartnerContribution::query()
                ->whereKey($contribution->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TransactionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => __('partners.errors.already_cancelled'),
                ]);
            }

            $locked->forceFill([
                'status' => TransactionStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->ledger->reverseAllFor(
                reference: $locked,
                description: __('partners.ledger.contribution_cancelled', [
                    'partner' => $locked->partner->name,
                ]),
                date: now()->toDateString(),
            );

            $this->audit->cancelled($locked, $reason, $locked->partner->name);

            return $locked->refresh();
        });
    }
}
