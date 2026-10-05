<?php

use App\Actions\Expenses\CreateExpense;
use App\Enums\AuditAction;
use App\Enums\LedgerDirection;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialLedgerEntry;
use App\Models\FundingAllocation;
use App\Models\Partner;
use App\Services\PartnerLedgerService;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '50000.00', bankOpening: '100000.00');
    $this->cash = $accounts['cash'];
    $this->bank = $accounts['bank'];

    $this->feed = ExpenseCategory::query()->where('code', 'animal_feed')->firstOrFail();
    $this->animalPurchase = ExpenseCategory::query()
        ->where('code', ExpenseCategory::ANIMAL_PURCHASE)->firstOrFail();

    $this->partnerA = Partner::factory()->for($this->business)->create(['name' => 'Partner A']);
    $this->partnerB = Partner::factory()->for($this->business)->create(['name' => 'Partner B']);

    $this->actor = superAdmin();
    $this->actingAs($this->actor);

    $this->create = app(CreateExpense::class);
});

/*
|--------------------------------------------------------------------------
| Single source: 10,000 from Cash
|--------------------------------------------------------------------------
*/

test('an expense funded entirely from one account posts one expense and one debit', function () {
    $expense = $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
    ]);

    expect(Expense::query()->count())->toBe(1)
        ->and(FundingAllocation::query()->count())->toBe(1)
        ->and(FinancialLedgerEntry::query()->count())->toBe(1);

    expectMoney($expense->amount, '10000.00');

    $allocation = FundingAllocation::query()->firstOrFail();
    expect($allocation->source_type)->toBe('financial_account')
        ->and($allocation->source_id)->toBe($this->cash->id)
        ->and($allocation->payable_type)->toBe('expense')
        ->and($allocation->payable_id)->toBe($expense->id);
    expectMoney($allocation->amount, '10000.00');

    $entry = FinancialLedgerEntry::query()->firstOrFail();
    expect($entry->direction)->toBe(LedgerDirection::Debit)
        ->and($entry->financial_account_id)->toBe($this->cash->id)
        ->and($entry->reference_type)->toBe('expense');
    expectMoney($entry->amount, '10000.00');

    expectMoney($this->cash->fresh()->balance(), '40000.00');
});

test('the expense total counts the expense, never the expense plus its allocations', function () {
    $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
    ]);

    // The failure this guards against is a report summing both tables and
    // reporting 20,000 of spending where 10,000 happened.
    expectMoney(Expense::query()->active()->sum('amount'), '10000.00');
});

test('creating an expense is audited, including the funding split', function () {
    $expense = $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
    ]);

    $logs = AuditLog::query()->where('auditable_type', 'expense')
        ->where('auditable_id', $expense->id)->get();

    expect($logs->pluck('action')->all())
        ->toContain(AuditAction::Created)
        ->toContain(AuditAction::Funded);
});

/*
|--------------------------------------------------------------------------
| Partner + Cash: 10,000 = 7,000 + 3,000
|--------------------------------------------------------------------------
*/

test('a partner-and-cash split debits only the cash share', function () {
    $expense = $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partnerA->id, 'amount' => '7000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
    ]);

    // One expense, two allocations.
    expect(Expense::query()->count())->toBe(1)
        ->and(FundingAllocation::query()->count())->toBe(2);

    expectMoney($expense->amount, '10000.00');
    expectMoney($expense->allocatedAmount(), '10000.00');

    $fromPartner = FundingAllocation::query()->where('source_type', 'partner')->firstOrFail();
    $fromCash = FundingAllocation::query()->where('source_type', 'financial_account')->firstOrFail();

    expectMoney($fromPartner->amount, '7000.00');
    expectMoney($fromCash->amount, '3000.00');

    /*
     * The invariant this whole test exists for: exactly one ledger entry, for
     * the account-funded share only. The partner's 7,000 never passed through
     * a business account, so debiting one would invent money the business
     * never held.
     */
    expect(FinancialLedgerEntry::query()->count())->toBe(1);

    $entry = FinancialLedgerEntry::query()->firstOrFail();
    expectMoney($entry->amount, '3000.00');

    expect(FinancialLedgerEntry::query()->where('amount', '7000.00')->exists())->toBeFalse();

    // Cash moved by 3,000 only.
    expectMoney($this->cash->fresh()->balance(), '47000.00');
    expectMoney($this->bank->fresh()->balance(), '100000.00');
});

test('the partner ledger shows the partner-funded share of a split expense', function () {
    $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partnerA->id, 'amount' => '7000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
    ]);

    $ledger = app(PartnerLedgerService::class);

    expectMoney($ledger->total($this->partnerA), '7000.00');
    expectMoney($ledger->total($this->partnerB), '0.00');

    $entries = $ledger->entries($this->partnerA);
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->type)->toBe('expense')
        ->and($entries->first()->description)->toBe('Feed');
});

/*
|--------------------------------------------------------------------------
| Multi-partner: 90,000 = 40,000 + 30,000 + 20,000
|--------------------------------------------------------------------------
*/

test('the ninety thousand three-way split records one expense and debits only the bank share', function () {
    // The MASTER_SPEC example. Phase 6 creates the animal; this is the expense
    // and funding half only.
    $expense = $this->create->handle([
        'expense_category_id' => $this->animalPurchase->id,
        'expense_date' => '2026-09-01',
        'amount' => '90000.00',
        'description' => 'Cow purchase',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partnerA->id, 'amount' => '40000.00'],
        ['source_type' => 'partner', 'source_id' => $this->partnerB->id, 'amount' => '30000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->bank->id, 'amount' => '20000.00'],
    ]);

    expect(Expense::query()->count())->toBe(1)
        ->and(FundingAllocation::query()->count())->toBe(3);

    expectMoney($expense->amount, '90000.00');
    expectMoney($expense->allocatedAmount(), '90000.00');

    // Exactly one ledger entry: the bank's 20,000.
    expect(FinancialLedgerEntry::query()->count())->toBe(1);
    expectMoney(FinancialLedgerEntry::query()->firstOrFail()->amount, '20000.00');

    // The 70,000 of partner money touched no account.
    expectMoney($this->bank->fresh()->balance(), '80000.00');
    expectMoney($this->cash->fresh()->balance(), '50000.00');

    $ledger = app(PartnerLedgerService::class);
    expectMoney($ledger->total($this->partnerA), '40000.00');
    expectMoney($ledger->total($this->partnerB), '30000.00');

    // And the business still spent 90,000, not 180,000.
    expectMoney(Expense::query()->active()->sum('amount'), '90000.00');
});

test('no animal is created by a Phase 2 animal purchase expense', function () {
    // Phase 6 owns that workflow; Phase 2 records only the money.
    $this->create->handle([
        'expense_category_id' => $this->animalPurchase->id,
        'expense_date' => '2026-09-01',
        'amount' => '90000.00',
        'description' => 'Cow purchase',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->bank->id, 'amount' => '90000.00'],
    ]);

    expect(Schema::hasTable('animals'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Decimal exactness
|--------------------------------------------------------------------------
*/

test('thirds that do not divide evenly still sum to the exact total', function () {
    $partnerC = Partner::factory()->for($this->business)->create(['name' => 'Partner C']);

    $expense = $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Three-way',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partnerA->id, 'amount' => '3333.33'],
        ['source_type' => 'partner', 'source_id' => $this->partnerB->id, 'amount' => '3333.33'],
        ['source_type' => 'partner', 'source_id' => $partnerC->id, 'amount' => '3333.34'],
    ]);

    expectMoney($expense->allocatedAmount(), '10000.00');
    expect($expense->isFullyFunded())->toBeTrue();
});

test('single-paisa amounts are preserved exactly', function () {
    $expense = $this->create->handle([
        'expense_category_id' => $this->feed->id,
        'expense_date' => '2026-09-01',
        'amount' => '0.03',
        'description' => 'Tiny',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '0.01'],
        ['source_type' => 'partner', 'source_id' => $this->partnerA->id, 'amount' => '0.02'],
    ]);

    expectMoney($expense->allocatedAmount(), '0.03');
    expectMoney($this->cash->fresh()->balance(), '49999.99');
});
