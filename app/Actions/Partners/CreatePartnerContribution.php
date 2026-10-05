<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\TransactionStatus;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\PartnerContribution;
use App\Services\AuditLogger;
use App\Services\FinancialLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money a partner puts into a business account.
 *
 * One entry, five effects, one transaction: the contribution row, the account
 * credit, the partner ledger, the cashbook and the audit record. The partner
 * ledger and cashbook are derived views, so nothing is written twice -- that is
 * the Enter Once, Update Everywhere rule in its smallest form.
 */
class CreatePartnerContribution
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Partner $partner, array $attributes): PartnerContribution
    {
        if (! $partner->is_active) {
            throw ValidationException::withMessages([
                'partner_id' => __('partners.errors.inactive_partner'),
            ]);
        }

        $account = FinancialAccount::query()->findOrFail($attributes['financial_account_id']);

        if ((int) $account->business_id !== (int) $partner->business_id) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('finance.accounts.errors.wrong_business'),
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('finance.accounts.errors.inactive_account'),
            ]);
        }

        return DB::transaction(function () use ($partner, $account, $attributes): PartnerContribution {
            $contribution = PartnerContribution::create([
                'partner_id' => $partner->getKey(),
                'financial_account_id' => $account->getKey(),
                'payment_method_id' => $attributes['payment_method_id'] ?? null,
                'contribution_date' => $attributes['contribution_date'],
                'amount' => $attributes['amount'],
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            $this->ledger->credit(
                account: $account,
                amount: (string) $contribution->amount,
                date: $contribution->contribution_date->toDateString(),
                description: __('partners.ledger.contribution', ['partner' => $partner->name]),
                idempotencyKey: FinancialLedgerService::key('partner_contribution', $contribution->getKey()),
                reference: $contribution,
            );

            $this->audit->created($contribution, [
                'partner_id' => $partner->getKey(),
                'financial_account_id' => $account->getKey(),
                'contribution_date' => $contribution->contribution_date->toDateString(),
                'amount' => (string) $contribution->amount,
            ], $partner->name);

            return $contribution->refresh();
        });
    }
}
