<?php

use App\Actions\Expenses\CancelExpense;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Partners\CreatePartnerContribution;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialLedgerEntry;
use App\Models\FundingAllocation;
use App\Models\MilkPriceRule;
use App\Models\Partner;
use App\Models\PartnerContribution;

/*
 * These prove the transactions are real, not decorative.
 *
 * Failures are injected with Eloquent model events registered inside the test.
 * Nothing in production code knows about them, so there is no test-only
 * failure switch shipped to users.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '50000.00');
    $this->cash = $accounts['cash'];

    $this->category = ExpenseCategory::query()->where('code', 'animal_feed')->firstOrFail();
    $this->partner = Partner::factory()->for($this->business)->create();

    $this->actingAs(superAdmin());
});

/** Makes the next insert of the given model throw. */
function failOnCreating(string $modelClass): void
{
    $modelClass::creating(function (): never {
        throw new RuntimeException('Injected failure');
    });
}

afterEach(function (): void {
    // Model event listeners are static, so they must not leak into other tests.
    FinancialLedgerEntry::flushEventListeners();
    FundingAllocation::flushEventListeners();
    AuditLog::flushEventListeners();
    MilkPriceRule::flushEventListeners();
    PartnerContribution::flushEventListeners();

    // Re-register the guards the models rely on.
    FinancialLedgerEntry::bootTraits();
    AuditLog::bootTraits();
});

test('an expense rolls back completely when the ledger posting fails', function () {
    failOnCreating(FinancialLedgerEntry::class);

    expect(fn () => app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
    ]))->toThrow(RuntimeException::class);

    // Nothing survives: not the expense, not the allocation, not the audit.
    expect(Expense::query()->count())->toBe(0)
        ->and(FundingAllocation::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'expense')->count())->toBe(0);

    expectMoney($this->cash->fresh()->balance(), '50000.00');
});

test('an expense rolls back when the audit write fails', function () {
    // A half-recorded expense is worse than none: the books would balance
    // while the paperwork disagrees.
    failOnCreating(AuditLog::class);

    expect(fn () => app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
    ]))->toThrow(RuntimeException::class);

    expect(Expense::query()->count())->toBe(0)
        ->and(FundingAllocation::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0);

    expectMoney($this->cash->fresh()->balance(), '50000.00');
});

test('an expense rolls back when the second allocation fails, leaving no partial split', function () {
    $calls = 0;

    FundingAllocation::creating(function () use (&$calls): void {
        $calls++;
        if ($calls === 2) {
            throw new RuntimeException('Injected failure on the second allocation');
        }
    });

    expect(fn () => app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '7000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
    ]))->toThrow(RuntimeException::class);

    expect(Expense::query()->count())->toBe(0)
        ->and(FundingAllocation::query()->count())->toBe(0);
});

test('a partner contribution rolls back when the ledger credit fails', function () {
    failOnCreating(FinancialLedgerEntry::class);

    expect(fn () => app(CreatePartnerContribution::class)->handle($this->partner, [
        'contribution_date' => '2026-09-01',
        'amount' => '5000.00',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(RuntimeException::class);

    expect(PartnerContribution::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'partner_contribution')->count())->toBe(0);

    expectMoney($this->cash->fresh()->balance(), '50000.00');
});

test('a partner contribution rolls back when the audit write fails', function () {
    failOnCreating(AuditLog::class);

    expect(fn () => app(CreatePartnerContribution::class)->handle($this->partner, [
        'contribution_date' => '2026-09-01',
        'amount' => '5000.00',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(RuntimeException::class);

    expect(PartnerContribution::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0);
});

test('a cancellation rolls back completely when the reversal fails', function () {
    $expense = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '3000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
    ]);

    expectMoney($this->cash->fresh()->balance(), '47000.00');

    // Fail on the reversal entry, after the status has already been written
    // inside the transaction.
    failOnCreating(FinancialLedgerEntry::class);

    expect(fn () => app(CancelExpense::class)->handle($expense, 'Trying to cancel'))
        ->toThrow(RuntimeException::class);

    // The status change rolled back with the reversal, so the expense is not
    // left cancelled-but-still-charged.
    expect($expense->fresh()->status)->toBe(TransactionStatus::Active)
        ->and($expense->fresh()->cancelled_at)->toBeNull()
        ->and(FinancialLedgerEntry::query()->count())->toBe(1);

    expectMoney($this->cash->fresh()->balance(), '47000.00');
});

test('a price period transition rolls back, leaving the previous period open', function () {
    $action = app(SetMilkPrice::class);

    $action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');

    $first = MilkPriceRule::query()->firstOrFail();
    expect($first->effective_to)->toBeNull();

    // Fail while inserting the new period, after the old one has been closed
    // inside the transaction.
    $calls = 0;
    MilkPriceRule::creating(function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('Injected failure');
    });

    expect(fn () => $action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-10-01'))
        ->toThrow(RuntimeException::class);

    // The close was undone: no gap where no price resolves.
    expect(MilkPriceRule::query()->count())->toBe(1)
        ->and($first->fresh()->effective_to)->toBeNull();

    expectMoney($first->fresh()->rate, '70.00');
});
