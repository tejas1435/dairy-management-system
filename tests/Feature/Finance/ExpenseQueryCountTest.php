<?php

use App\Actions\Expenses\CreateExpense;
use App\Models\ExpenseCategory;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;

/*
 * Query-count guards for the two Phase 2 screens that render a variable number
 * of related records.
 *
 * These assert a ceiling rather than an exact number: an exact count turns any
 * harmless framework change into a failing test, while a ceiling still catches
 * the thing that matters, which is a query that grows with the number of rows.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '500000.00', bankOpening: '500000.00');
    $this->cash = $accounts['cash'];
    $this->bank = $accounts['bank'];
    $this->category = ExpenseCategory::query()->firstOrFail();

    $this->admin = superAdmin();
});

/**
 * Counts the queries a callback runs.
 *
 * Call warmRequestCaches() before the first measurement: the first authenticated
 * request of a test loads the permission cache and the session, which makes it
 * several queries more expensive than every request after it.
 */
function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** One unmeasured request, so per-request caches are already loaded. */
function warmRequestCaches(string $url): void
{
    test()->get($url)->assertOk();
}

test('the expense detail page costs the same however many funding sources there are', function () {
    // A mixed split covering both source types, which is where a morphTo
    // relation would otherwise resolve one query per row.
    $partners = Partner::factory()->count(4)->for($this->business)->create();

    $allocations = [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '1000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->bank->id, 'amount' => '1000.00'],
    ];

    foreach ($partners as $partner) {
        $allocations[] = ['source_type' => 'partner', 'source_id' => $partner->id, 'amount' => '2000.00'];
    }

    $big = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Six-way split',
    ], $allocations);

    // The comparison expense carries both source types too, because a morphTo
    // eager load costs one query per distinct type present: comparing a
    // two-type split against a one-type split would report that as growth.
    $small = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-02',
        'amount' => '1000.00',
        'description' => 'Two-way split',
    ], [
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '500.00'],
        ['source_type' => 'partner', 'source_id' => $partners->first()->id, 'amount' => '500.00'],
    ]);

    $this->actingAs($this->admin);
    warmRequestCaches(route('finance.expenses.show', $small));

    $smallCount = countQueries(fn () => $this->get(route('finance.expenses.show', $small))->assertOk());
    $bigCount = countQueries(fn () => $this->get(route('finance.expenses.show', $big))->assertOk());

    // Six sources of two types must not cost more than one source does: the
    // morphTo relation is loaded for the whole set, not one row at a time.
    expect($bigCount)->toBe($smallCount);
});

test('the expense detail page renders every funding source type correctly', function () {
    $partner = Partner::factory()->for($this->business)->create(['name' => 'Partner A']);

    $expense = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Mixed funding',
    ], [
        ['source_type' => 'partner', 'source_id' => $partner->id, 'amount' => '7000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
    ]);

    $this->actingAs($this->admin)->get(route('finance.expenses.show', $expense))
        ->assertOk()
        // Both source names resolve, neither falls back to the em-dash.
        ->assertSee('Partner A')
        ->assertSee('Cash')
        ->assertSee(__('finance.funding.source_types.partner'))
        ->assertSee(__('finance.funding.source_types.financial_account'));
});

test('a partner-only expense and an account-only expense both render their source', function () {
    $partner = Partner::factory()->for($this->business)->create(['name' => 'Solo Partner']);

    $partnerFunded = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '1000.00',
        'description' => 'Partner only',
    ], [['source_type' => 'partner', 'source_id' => $partner->id, 'amount' => '1000.00']]);

    $accountFunded = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-02',
        'amount' => '1000.00',
        'description' => 'Account only',
    ], [['source_type' => 'financial_account', 'source_id' => $this->bank->id, 'amount' => '1000.00']]);

    $this->actingAs($this->admin);

    $this->get(route('finance.expenses.show', $partnerFunded))->assertOk()->assertSee('Solo Partner');
    $this->get(route('finance.expenses.show', $accountFunded))->assertOk()->assertSee('Main Bank Account');
});

/**
 * Records `$count` split-funded expenses, each drawing half from the given
 * partner and half from cash.
 */
function recordSplitExpenses(int $count, Partner $partner, ExpenseCategory $category, $cash): void
{
    foreach (range(1, $count) as $i) {
        app(CreateExpense::class)->handle([
            'expense_category_id' => $category->id,
            'expense_date' => '2026-09-01',
            'amount' => '200.00',
            'description' => "Expense {$i}",
        ], [
            ['source_type' => 'partner', 'source_id' => $partner->id, 'amount' => '100.00'],
            ['source_type' => 'financial_account', 'source_id' => $cash->id, 'amount' => '100.00'],
        ]);
    }
}

/*
 * The two list comparisons below measure one row against many, never many
 * against none. Eloquent skips an eager-load query altogether when the parent
 * collection is empty, so an empty baseline reports a difference that is really
 * the eager loads firing for the first time rather than a query per row.
 */

test('the expense list costs the same however many expenses it shows', function () {
    $partner = Partner::factory()->for($this->business)->create();
    recordSplitExpenses(1, $partner, $this->category, $this->cash);

    $this->actingAs($this->admin);
    warmRequestCaches(route('finance.expenses.index'));

    $withOne = countQueries(fn () => $this->get(route('finance.expenses.index'))->assertOk());

    recordSplitExpenses(8, $partner, $this->category, $this->cash);

    $withNine = countQueries(fn () => $this->get(route('finance.expenses.index'))->assertOk());

    expect($withNine)->toBe($withOne);
});

test('the partner ledger costs the same however many funded expenses it lists', function () {
    $one = Partner::factory()->for($this->business)->create();
    $many = Partner::factory()->for($this->business)->create();

    recordSplitExpenses(1, $one, $this->category, $this->cash);
    recordSplitExpenses(6, $many, $this->category, $this->cash);

    $this->actingAs($this->admin);
    warmRequestCaches(route('finance.partners.show', $one));

    $withOne = countQueries(fn () => $this->get(route('finance.partners.show', $one))->assertOk());
    $withSix = countQueries(fn () => $this->get(route('finance.partners.show', $many))->assertOk());

    expect($withSix)->toBe($withOne);
});
