<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\FundingAllocation;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Services\FinancialLedgerService;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Records who paid for a payable, and posts the resulting account movements.
 *
 * This is the reusable mechanism behind every multi-source payment in the
 * product. Phase 2 funds expenses with it; Phase 6 funds animal purchases and
 * Phase 7 funds payroll payments and loan disbursements through exactly this
 * code, by passing a different payable.
 *
 * Two rules do the real work:
 *
 *  - **The split must be exact.** The allocations must sum to the payable's
 *    amount, checked server-side with decimal arithmetic inside the caller's
 *    transaction. The browser's running total is a convenience, never the
 *    authority.
 *
 *  - **Only business money moves the ledger.** An account-funded share debits
 *    that account. A partner-funded share debits nothing, because the money
 *    never passed through a business account; it shows up on the partner's
 *    ledger instead. Debiting an account for a partner's share would invent
 *    money the business never spent.
 *
 * Allocations are not expenses. A 10,000 expense split two ways is one expense
 * row and two allocation rows, and every total must keep it that way.
 */
class AllocateFundingSources
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    /**
     * @param  array<int, array{source_type: string, source_id: int|string, amount: string|float|int, payment_method_id?: int|string|null, reference?: string|null}>  $allocations
     * @param  string  $purpose  idempotency purpose prefix, e.g. "expense_funding"
     *
     * @throws ValidationException
     */
    public function handle(
        Model $payable,
        array $allocations,
        string $expectedTotal,
        string $date,
        string $description,
        string $purpose,
    ): Collection {
        $this->assertPayableSupported($payable);

        $normalised = $this->normalise($allocations);

        $this->assertNoDuplicateSources($normalised);
        $this->assertTotalMatches($normalised, $expectedTotal);

        $sources = $this->resolveSources($normalised, $payable);

        $created = new Collection;

        foreach ($normalised as $index => $row) {
            $source = $sources[$index];

            $allocation = FundingAllocation::create([
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'source_type' => $row['source_type'],
                'source_id' => $source->getKey(),
                'amount' => $row['amount'],
                'payment_method_id' => $row['payment_method_id'],
                'reference' => $row['reference'],
                'created_by' => Auth::id(),
            ]);

            /*
             * Only a business account moves. The key is derived from the
             * allocation id, so re-running this operation cannot post the debit
             * twice.
             */
            if ($source instanceof FinancialAccount) {
                $this->ledger->debit(
                    account: $source,
                    amount: (string) $allocation->amount,
                    date: $date,
                    description: $description,
                    idempotencyKey: FinancialLedgerService::key($purpose, $allocation->getKey()),
                    reference: $payable,
                );
            }

            $created->push($allocation);
        }

        return $created;
    }

    /**
     * Cleans and type-checks the incoming rows.
     *
     * @return array<int, array{source_type: string, source_id: int, amount: string, payment_method_id: int|null, reference: string|null}>
     */
    private function normalise(array $allocations): array
    {
        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => __('finance.funding.errors.none_provided'),
            ]);
        }

        $normalised = [];

        foreach (array_values($allocations) as $index => $row) {
            $type = (string) ($row['source_type'] ?? '');

            if (! in_array($type, MorphMap::fundingSources(), true)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.source_type" => __('finance.funding.errors.invalid_source_type'),
                ]);
            }

            $amount = $this->decimal($row['amount'] ?? null);

            if ($amount === null || bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => __('finance.funding.errors.amount_positive'),
                ]);
            }

            $normalised[] = [
                'source_type' => $type,
                'source_id' => (int) ($row['source_id'] ?? 0),
                'amount' => $amount,
                'payment_method_id' => filled($row['payment_method_id'] ?? null)
                    ? (int) $row['payment_method_id']
                    : null,
                'reference' => filled($row['reference'] ?? null) ? (string) $row['reference'] : null,
            ];
        }

        return $normalised;
    }

    /**
     * The same source twice in one split is almost always a mis-click, and it
     * makes the partner ledger read as two payments where there was one. The
     * user is asked to combine them instead.
     */
    private function assertNoDuplicateSources(array $rows): void
    {
        $seen = [];

        foreach ($rows as $index => $row) {
            $key = $row['source_type'].':'.$row['source_id'];

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.source_id" => __('finance.funding.errors.duplicate_source'),
                ]);
            }

            $seen[$key] = true;
        }
    }

    private function assertTotalMatches(array $rows, string $expectedTotal): void
    {
        $sum = '0.00';

        foreach ($rows as $row) {
            $sum = bcadd($sum, $row['amount'], 2);
        }

        $comparison = bccomp($sum, $expectedTotal, 2);

        if ($comparison === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'allocations' => $comparison < 0
                ? __('finance.funding.errors.under_allocated', [
                    'short' => $this->money(bcsub($expectedTotal, $sum, 2)),
                ])
                : __('finance.funding.errors.over_allocated', [
                    'excess' => $this->money(bcsub($sum, $expectedTotal, 2)),
                ]),
        ]);
    }

    /**
     * Loads each source and refuses anything that is missing, inactive, or
     * belongs to a different business.
     *
     * An id arriving in a request proves nothing, so every one is checked
     * against the payable's business rather than trusted.
     *
     * @return array<int, Partner|FinancialAccount>
     */
    private function resolveSources(array $rows, Model $payable): array
    {
        $businessId = $payable->getAttribute('business_id');
        $resolved = [];

        foreach ($rows as $index => $row) {
            $model = match ($row['source_type']) {
                'partner' => Partner::query()->whereKey($row['source_id'])->first(),
                'financial_account' => FinancialAccount::query()->whereKey($row['source_id'])->first(),
                default => null,
            };

            if (! $model) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.source_id" => __('finance.funding.errors.source_not_found'),
                ]);
            }

            if ($businessId !== null && (int) $model->getAttribute('business_id') !== (int) $businessId) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.source_id" => __('finance.funding.errors.source_not_found'),
                ]);
            }

            if (! $model->getAttribute('is_active')) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.source_id" => __('finance.funding.errors.source_inactive', [
                        'name' => $model->getAttribute('name'),
                    ]),
                ]);
            }

            if ($row['payment_method_id'] !== null
                && ! PaymentMethod::query()->whereKey($row['payment_method_id'])->exists()) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.payment_method_id" => __('finance.funding.errors.invalid_payment_method'),
                ]);
            }

            $resolved[$index] = $model;
        }

        return $resolved;
    }

    private function assertPayableSupported(Model $payable): void
    {
        if (! in_array($payable->getMorphClass(), MorphMap::fundingPayables(), true)) {
            throw ValidationException::withMessages([
                'allocations' => __('finance.funding.errors.unsupported_payable'),
            ]);
        }
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return bcadd((string) $value, '0', 2);
    }

    private function money(string $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }
}
