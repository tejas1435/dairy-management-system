<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\MilkPriceRule;
use App\Models\Partner;
use App\Models\PartnerContribution;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->accounts = seedAccounts($this->business, cashOpening: '10000.00');
    $this->partner = Partner::factory()->for($this->business)->create();
    $this->category = ExpenseCategory::query()->firstOrFail();
    $this->expense = Expense::factory()->for($this->business)
        ->create(['expense_category_id' => $this->category->id]);
    $this->contribution = PartnerContribution::factory()
        ->for($this->partner)
        ->create(['financial_account_id' => $this->accounts['cash']->id]);
});

/*
|--------------------------------------------------------------------------
| Reads
|--------------------------------------------------------------------------
*/

test('a Phase 2 page is refused without its permission and allowed with it', function (string $routeName, string $permission) {
    $without = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    $this->actingAs($without)->get(route($routeName))->assertForbidden();

    $with = userWithPermissions([$permission]);
    $this->actingAs($with)->get(route($routeName))->assertOk();
})->with([
    'accounts' => ['finance.accounts.index', 'finance.account.manage'],
    'cashbook' => ['finance.cashbook', 'finance.cashbook.view'],
    'partners' => ['finance.partners.index', 'partner.view'],
    'expenses' => ['finance.expenses.index', 'expense.view'],
    'audit' => ['admin.audit.index', 'audit.view'],
    'payment methods' => ['settings.payment-methods.index', 'settings.manage'],
    'expense categories' => ['settings.expense-categories.index', 'settings.manage'],
    'sales channels' => ['settings.sales-channels.index', 'settings.manage'],
    'milk prices' => ['settings.milk-prices.index', 'settings.manage'],
]);

/*
|--------------------------------------------------------------------------
| Writes: the ones a hidden button would not protect
|--------------------------------------------------------------------------
*/

test('creating an expense is refused without expense.create', function () {
    $user = userWithPermissions(['expense.view']);

    $this->actingAs($user)->get(route('finance.expenses.create'))->assertForbidden();

    $this->actingAs($user)->post(route('finance.expenses.store'), [
        'expense_date' => '2026-09-01',
        'expense_category_id' => $this->category->id,
        'amount' => '100.00',
        'description' => 'Sneaky',
        'allocations' => [[
            'source_type' => 'financial_account',
            'source_id' => $this->accounts['cash']->id,
            'amount' => '100.00',
        ]],
    ])->assertForbidden();

    expect(Expense::query()->where('description', 'Sneaky')->exists())->toBeFalse();
});

test('editing an expense is refused without expense.update', function () {
    $user = userWithPermissions(['expense.view']);

    $this->actingAs($user)->put(route('finance.expenses.update', $this->expense), [
        'expense_category_id' => $this->category->id,
        'description' => 'Hijacked',
    ])->assertForbidden();

    expect($this->expense->fresh()->description)->not->toBe('Hijacked');
});

test('cancelling an expense is refused without expense.cancel', function () {
    $user = userWithPermissions(['expense.view', 'expense.update']);

    $this->actingAs($user)->put(route('finance.expenses.cancel', $this->expense), [
        'cancellation_reason' => 'Trying it on',
    ])->assertForbidden();

    expect($this->expense->fresh()->isCancelled())->toBeFalse();
});

test('creating a partner is refused without partner.create', function () {
    $user = userWithPermissions(['partner.view']);

    $this->actingAs($user)->get(route('finance.partners.create'))->assertForbidden();
    $this->actingAs($user)->post(route('finance.partners.store'), ['name' => 'Sneaky Partner'])
        ->assertForbidden();

    expect(Partner::query()->where('name', 'Sneaky Partner')->exists())->toBeFalse();
});

test('updating or deactivating a partner is refused without partner.update', function () {
    $user = userWithPermissions(['partner.view']);

    $this->actingAs($user)->put(route('finance.partners.update', $this->partner), ['name' => 'Hijacked'])
        ->assertForbidden();

    $this->actingAs($user)->put(route('finance.partners.status.update', $this->partner), ['is_active' => 0])
        ->assertForbidden();

    expect($this->partner->fresh()->name)->not->toBe('Hijacked')
        ->and($this->partner->fresh()->is_active)->toBeTrue();
});

test('recording a contribution is refused without partner.contribution.create', function () {
    $user = userWithPermissions(['partner.view', 'partner.update']);

    $this->actingAs($user)->post(route('finance.partners.contributions.store', $this->partner), [
        'contribution_date' => '2026-09-01',
        'amount' => '500.00',
        'financial_account_id' => $this->accounts['cash']->id,
    ])->assertForbidden();

    expect(PartnerContribution::query()->count())->toBe(1);
});

test('cancelling a contribution is refused without partner.contribution.create', function () {
    $user = userWithPermissions(['partner.view']);

    $this->actingAs($user)->put(route('finance.contributions.cancel', $this->contribution), [
        'cancellation_reason' => 'Trying it on',
    ])->assertForbidden();

    expect($this->contribution->fresh()->isCancelled())->toBeFalse();
});

test('managing accounts is refused without finance.account.manage', function () {
    $user = userWithPermissions(['finance.cashbook.view']);

    $this->actingAs($user)->post(route('finance.accounts.store'), [
        'name' => 'Sneaky', 'type' => 'cash', 'opening_balance' => '0',
    ])->assertForbidden();

    $this->actingAs($user)->put(route('finance.accounts.status.update', $this->accounts['cash']), [
        'is_active' => 0,
    ])->assertForbidden();

    expect(FinancialAccount::query()->where('name', 'Sneaky')->exists())->toBeFalse()
        ->and($this->accounts['cash']->fresh()->is_active)->toBeTrue();
});

test('setting a milk price is refused without settings.manage', function () {
    $user = userWithPermissions(['dashboard.view']);

    $this->actingAs($user)->post(route('settings.milk-prices.store'), [
        'milk_type' => 'cow', 'rate' => '1.00', 'effective_from' => '2026-09-01',
    ])->assertForbidden();

    expect(MilkPriceRule::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Partner financial visibility is separable from partner visibility
|--------------------------------------------------------------------------
*/

test('partner.view alone shows the partner but not their money', function () {
    $user = userWithPermissions(['partner.view']);

    $this->actingAs($user)->get(route('finance.partners.show', $this->partner))
        ->assertOk()
        // The ledger section is replaced by a forbidden state rather than
        // silently showing zeroes.
        ->assertSee(__('app.errors.forbidden'));
});

test('partner.finance.view unlocks the ledger', function () {
    $user = userWithPermissions(['partner.view', 'partner.finance.view']);

    $this->actingAs($user)->get(route('finance.partners.show', $this->partner))
        ->assertOk()
        ->assertSee(__('partners.ledger.columns.amount'));
});

/*
|--------------------------------------------------------------------------
| Role matrix behaviour on the real Phase 2 surface
|--------------------------------------------------------------------------
*/

test('the seeded Data Operator cannot reach any money screen', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::DATA_OPERATOR);

    foreach (['finance.expenses.index', 'finance.partners.index', 'finance.accounts.index', 'finance.cashbook'] as $route) {
        $this->actingAs($user->fresh())->get(route($route))->assertForbidden();
    }
});

test('the seeded Accountant reaches expenses and partners but not account management', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::ACCOUNTANT);

    $this->actingAs($user->fresh())->get(route('finance.expenses.index'))->assertOk();
    $this->actingAs($user->fresh())->get(route('finance.partners.index'))->assertOk();
    $this->actingAs($user->fresh())->get(route('finance.cashbook'))->assertOk();

    // Creating and editing the accounts themselves is an owner-level act.
    $this->actingAs($user->fresh())->get(route('finance.accounts.index'))->assertForbidden();
});

test('the seeded Viewer can read expenses but change nothing', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::VIEWER);

    $this->actingAs($user->fresh())->get(route('finance.expenses.index'))->assertOk();
    $this->actingAs($user->fresh())->get(route('finance.expenses.create'))->assertForbidden();
    $this->actingAs($user->fresh())->put(route('finance.expenses.cancel', $this->expense), [
        'cancellation_reason' => 'No',
    ])->assertForbidden();

    // And no financial visibility.
    $this->actingAs($user->fresh())->get(route('finance.cashbook'))->assertForbidden();
});

test('a revoked permission takes effect on the next request across Phase 2 too', function () {
    $user = userWithPermissions(['expense.view']);
    $role = $user->roles->first();

    $this->actingAs($user)->get(route('finance.expenses.index'))->assertOk();

    $role->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())->get(route('finance.expenses.index'))->assertForbidden();
});
