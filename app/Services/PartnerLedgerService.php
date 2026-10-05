<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Models\FundingAllocation;
use App\Models\Partner;
use App\Models\PartnerContribution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a partner's ledger from the records that already exist.
 *
 * **There is no partner_ledger_entries table, and there should not be one.**
 * Every line here is derived from a contribution or a funding allocation that
 * is already the system's record of that money. A parallel ledger table would
 * be a second copy of the same facts, free to drift, with no way to tell which
 * copy is right when it does.
 *
 * Phase 2 derives from two sources:
 *   - partner contributions into business accounts;
 *   - expenses the partner paid for directly.
 *
 * Phases 6 and 7 extend the same method with animal purchases, payroll payments
 * and loan disbursements, because those all fund through funding_allocations
 * too. Nothing about the shape of this service changes when they arrive.
 *
 * Cancelled records are excluded: a cancelled expense is not money the partner
 * spent.
 */
class PartnerLedgerService
{
    /**
     * @return Collection<int, object{date: Carbon, type: string, reference: string, description: string, amount: string, source_id: int}>
     */
    public function entries(Partner $partner, ?string $from = null, ?string $to = null): Collection
    {
        return $this->contributions($partner, $from, $to)
            ->concat($this->fundedExpenses($partner, $from, $to))
            ->sortBy([['date', 'desc'], ['reference', 'desc']])
            ->values();
    }

    /** The partner's total contribution over the period, as a decimal string. */
    public function total(Partner $partner, ?string $from = null, ?string $to = null): string
    {
        return $this->entries($partner, $from, $to)
            ->reduce(fn (string $carry, object $entry): string => bcadd($carry, $entry->amount, 2), '0.00');
    }

    /**
     * Totals for many partners at once, for the partner list.
     *
     * @param  Collection<int, Partner>  $partners
     * @return array<int, string> keyed by partner id
     */
    public function totalsFor(Collection $partners): array
    {
        if ($partners->isEmpty()) {
            return [];
        }

        $ids = $partners->pluck('id')->all();

        $contributions = PartnerContribution::query()
            ->active()
            ->whereIn('partner_id', $ids)
            ->selectRaw('partner_id, SUM(amount) as total')
            ->groupBy('partner_id')
            ->pluck('total', 'partner_id');

        $funded = FundingAllocation::query()
            ->where('source_type', 'partner')
            ->whereIn('source_id', $ids)
            ->where('payable_type', 'expense')
            ->whereIn('payable_id', Expense::query()->active()->select('id'))
            ->selectRaw('source_id, SUM(amount) as total')
            ->groupBy('source_id')
            ->pluck('total', 'source_id');

        $totals = [];

        foreach ($ids as $id) {
            $totals[$id] = bcadd(
                (string) ($contributions[$id] ?? '0'),
                (string) ($funded[$id] ?? '0'),
                2
            );
        }

        return $totals;
    }

    /** @return Collection<int, object> */
    private function contributions(Partner $partner, ?string $from, ?string $to): Collection
    {
        return PartnerContribution::query()
            ->with(['account:id,name', 'paymentMethod:id,name'])
            ->active()
            ->where('partner_id', $partner->getKey())
            ->when($from, fn ($q) => $q->whereDate('contribution_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('contribution_date', '<=', $to))
            ->orderByDesc('contribution_date')
            ->get()
            ->map(fn (PartnerContribution $contribution): object => (object) [
                'date' => $contribution->contribution_date,
                'type' => 'contribution',
                'type_label' => __('partners.ledger.types.contribution'),
                'reference' => $contribution->reference ?: '#'.$contribution->getKey(),
                'description' => __('partners.ledger.into_account', [
                    'account' => $contribution->account?->name ?? '',
                ]),
                'amount' => (string) $contribution->amount,
                'source_id' => $contribution->getKey(),
                'url' => null,
            ]);
    }

    /** @return Collection<int, object> */
    private function fundedExpenses(Partner $partner, ?string $from, ?string $to): Collection
    {
        return FundingAllocation::query()
            ->where('source_type', 'partner')
            ->where('source_id', $partner->getKey())
            ->where('payable_type', 'expense')
            ->whereIn('payable_id', Expense::query()
                ->where('status', TransactionStatus::Active->value)
                ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
                ->select('id'))
            ->with(['payable' => fn ($q) => $q->with('category:id,name')])
            ->get()
            ->map(function (FundingAllocation $allocation): ?object {
                /** @var Expense|null $expense */
                $expense = $allocation->payable;

                if (! $expense) {
                    return null;
                }

                return (object) [
                    'date' => $expense->expense_date,
                    'type' => 'expense',
                    'type_label' => __('partners.ledger.types.expense'),
                    'reference' => $allocation->reference ?: '#'.$expense->getKey(),
                    'description' => $expense->description,
                    'amount' => (string) $allocation->amount,
                    'source_id' => $expense->getKey(),
                    'url' => route('finance.expenses.show', $expense),
                ];
            })
            ->filter()
            ->values();
    }
}
