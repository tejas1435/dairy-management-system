<?php

use App\Actions\Expenses\CreateExpense;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\FundingAllocation;
use App\Models\Partner;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '50000.00');
    $this->cash = $accounts['cash'];

    $this->category = ExpenseCategory::query()->where('code', 'animal_feed')->firstOrFail();
    $this->partner = Partner::factory()->for($this->business)->create();

    $this->actingAs(superAdmin());
    $this->create = app(CreateExpense::class);
});

/** Attempts an expense of 10,000 with the given allocations. */
function attemptExpense(array $allocations, string $amount = '10000.00'): void
{
    test()->create->handle([
        'expense_category_id' => test()->category->id,
        'expense_date' => '2026-09-01',
        'amount' => $amount,
        'description' => 'Feed',
    ], $allocations);
}

/** Nothing at all should survive a rejected attempt. */
function expectNothingPersisted(): void
{
    expect(Expense::query()->count())->toBe(0)
        ->and(FundingAllocation::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0);

    expectMoney(test()->cash->fresh()->balance(), '50000.00');
}

test('underfunding by a single paisa is rejected and nothing is written', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '9999.99'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('overfunding by a single paisa is rejected and nothing is written', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.01'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('a negative allocation is rejected', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '12000.00'],
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '-2000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('a zero allocation is rejected', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '0.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('an empty allocation list is rejected', function () {
    expect(fn () => attemptExpense([]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('an unknown source type is rejected', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'petty_cash_tin', 'source_id' => 1, 'amount' => '10000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('a source that does not exist is rejected', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => 99999, 'amount' => '10000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('an account belonging to another business is rejected', function () {
    // An id arriving in a request proves nothing about who owns it.
    $other = Business::factory()->create();
    $foreign = FinancialAccount::factory()->for($other)->create();

    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $foreign->id, 'amount' => '10000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('a partner belonging to another business is rejected', function () {
    $other = Business::factory()->create();
    $foreign = Partner::factory()->for($other)->create();

    expect(fn () => attemptExpense([
        ['source_type' => 'partner', 'source_id' => $foreign->id, 'amount' => '10000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('an inactive account cannot fund an expense', function () {
    $inactive = FinancialAccount::factory()->for($this->business)->inactive()->create();

    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $inactive->id, 'amount' => '10000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('an inactive partner cannot fund an expense', function () {
    $inactive = Partner::factory()->for($this->business)->inactive()->create();

    expect(fn () => attemptExpense([
        ['source_type' => 'partner', 'source_id' => $inactive->id, 'amount' => '10000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('the same source listed twice is rejected rather than silently doubled', function () {
    // Almost always a mis-click, and it would read as two payments on the
    // partner ledger where there was one.
    expect(fn () => attemptExpense([
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '5000.00'],
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '5000.00'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('the same id under different source types is allowed, because they are different records', function () {
    // Partner #1 and Account #1 are unrelated; only type+id together identify
    // a source.
    $partner = Partner::factory()->for($this->business)->create();
    $account = FinancialAccount::factory()->for($this->business)->create();

    attemptExpense([
        ['source_type' => 'partner', 'source_id' => $partner->id, 'amount' => '6000.00'],
        ['source_type' => 'financial_account', 'source_id' => $account->id, 'amount' => '4000.00'],
    ]);

    expect(FundingAllocation::query()->count())->toBe(2);
});

test('a payment method that does not exist is rejected', function () {
    expect(fn () => attemptExpense([
        [
            'source_type' => 'financial_account',
            'source_id' => $this->cash->id,
            'amount' => '10000.00',
            'payment_method_id' => 99999,
        ],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

test('a non-numeric amount is rejected', function () {
    expect(fn () => attemptExpense([
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => 'ten thousand'],
    ]))->toThrow(ValidationException::class);

    expectNothingPersisted();
});

/*
|--------------------------------------------------------------------------
| Through the HTTP form, not only the action
|--------------------------------------------------------------------------
*/

test('the form rejects an unbalanced split and saves nothing', function () {
    $this->post(route('finance.expenses.store'), [
        'expense_date' => '2026-09-01',
        'expense_category_id' => $this->category->id,
        'amount' => '10000.00',
        'description' => 'Feed',
        'allocations' => [
            ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '8000.00'],
        ],
    ])->assertSessionHasErrors('allocations');

    expectNothingPersisted();
});

test('the form rejects a submission with no funding rows', function () {
    $this->post(route('finance.expenses.store'), [
        'expense_date' => '2026-09-01',
        'expense_category_id' => $this->category->id,
        'amount' => '10000.00',
        'description' => 'Feed',
        'allocations' => [],
    ])->assertSessionHasErrors('allocations');

    expectNothingPersisted();
});

test('the form accepts a balanced split end to end', function () {
    $this->post(route('finance.expenses.store'), [
        'expense_date' => '2026-09-01',
        'expense_category_id' => $this->category->id,
        'amount' => '10000.00',
        'description' => 'Feed',
        'allocations' => [
            ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '7000.00'],
            ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
        ],
    ])->assertRedirect();

    expect(Expense::query()->count())->toBe(1)
        ->and(FundingAllocation::query()->count())->toBe(2)
        ->and(FinancialLedgerEntry::query()->count())->toBe(1);

    expectMoney($this->cash->fresh()->balance(), '47000.00');
});

test('blank trailing rows in the form are ignored rather than failing validation', function () {
    $this->post(route('finance.expenses.store'), [
        'expense_date' => '2026-09-01',
        'expense_category_id' => $this->category->id,
        'amount' => '10000.00',
        'description' => 'Feed',
        'allocations' => [
            ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '10000.00'],
            ['source_type' => 'financial_account', 'source_id' => '', 'amount' => ''],
        ],
    ])->assertRedirect();

    expect(FundingAllocation::query()->count())->toBe(1);
});
