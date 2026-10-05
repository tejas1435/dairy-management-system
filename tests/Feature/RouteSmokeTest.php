<?php

use App\Actions\Partners\CreatePartnerContribution;
use App\Enums\MilkType;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\MilkPriceRule;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SalesChannelSeeder;

/*
 * Pass 1 smoke tests.
 *
 * These prove the application is navigable: every GET route renders, with no
 * missing view, missing translation key, undefined Blade variable or unmapped
 * component. They deliberately assert almost nothing about behaviour -- the
 * real Phase 2 business suite covers ledger arithmetic, funding invariants,
 * cancellation and price resolution, and is not part of this pass.
 *
 * A page that renders is not a page that is correct. This file only rules out
 * the class of failure where a route 500s before any logic runs.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->seed(PaymentMethodSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);
    $this->seed(SalesChannelSeeder::class);

    $this->admin = superAdmin();
});

/** Every Phase 2 index and settings page a Super Admin can reach. */
test('every phase 2 listing page renders', function (string $routeName) {
    $this->actingAs($this->admin)->get(route($routeName))->assertOk();
})->with([
    'finance.accounts.index',
    'finance.accounts.create',
    'finance.cashbook',
    'finance.partners.index',
    'finance.partners.create',
    'finance.expenses.index',
    'finance.expenses.create',
    'buyers.index',
    'buyers.create',
    'admin.audit.index',
    'settings.payment-methods.index',
    'settings.expense-categories.index',
    'settings.sales-channels.index',
    'settings.milk-prices.index',
]);

test('the phase 1 pages still render alongside the new ones', function (string $routeName) {
    $this->actingAs($this->admin)->get(route($routeName))->assertOk();
})->with([
    'dashboard',
    'profile.edit',
    'admin.users.index',
    'admin.roles.index',
    'settings.business.edit',
    'settings.farms.index',
]);

test('financial account detail and edit render', function () {
    $account = FinancialAccount::factory()->for($this->business)->create();

    $this->actingAs($this->admin)->get(route('finance.accounts.show', $account))->assertOk();
    $this->actingAs($this->admin)->get(route('finance.accounts.edit', $account))->assertOk();
});

test('partner profile and edit render, including the empty ledger', function () {
    $partner = Partner::factory()->for($this->business)->create();

    $this->actingAs($this->admin)->get(route('finance.partners.show', $partner))
        ->assertOk()
        ->assertSee(__('partners.ledger.title'));

    $this->actingAs($this->admin)->get(route('finance.partners.edit', $partner))->assertOk();
});

test('expense detail and edit render for a funded expense', function () {
    $expense = Expense::factory()->for($this->business)->create([
        'expense_category_id' => ExpenseCategory::query()->firstOrFail()->id,
    ]);

    $this->actingAs($this->admin)->get(route('finance.expenses.show', $expense))->assertOk();
    $this->actingAs($this->admin)->get(route('finance.expenses.edit', $expense))->assertOk();
});

test('buyer detail and edit render with price resolution', function () {
    $channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $buyer = Buyer::factory()->inChannel($channel)->create();

    MilkPriceRule::factory()->for($this->business)->forType(MilkType::Cow)->create();

    $this->actingAs($this->admin)->get(route('buyers.show', $buyer))->assertOk();
    $this->actingAs($this->admin)->get(route('buyers.edit', $buyer))->assertOk();
});

test('the audit detail page renders', function () {
    // Any mutation produces an entry; a payment method rename is the cheapest.
    $method = PaymentMethod::query()->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('settings.payment-methods.update', $method), ['name' => 'Renamed'])
        ->assertRedirect();

    $log = AuditLog::query()->latest('id')->firstOrFail();

    $this->actingAs($this->admin)->get(route('admin.audit.show', $log))->assertOk();
});

test('the cashbook renders with ledger rows and a running balance', function () {
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create();

    $partner = Partner::factory()->for($this->business)->create();

    app(CreatePartnerContribution::class)->handle($partner, [
        'contribution_date' => now()->toDateString(),
        'amount' => '500.00',
        'financial_account_id' => $account->id,
    ]);

    $this->actingAs($this->admin)->get(route('finance.cashbook', ['account' => $account->id]))
        ->assertOk()
        ->assertSee(__('finance.cashbook.opening_balance'))
        ->assertSee(__('finance.cashbook.closing_balance'));
});

test('every page renders in gujarati and hindi without a missing key', function (string $locale) {
    // A missing translation key renders as the raw dotted key, which is the
    // most common way a localised page silently breaks.
    $this->admin->forceFill(['locale' => $locale])->save();

    foreach (['dashboard', 'finance.expenses.index', 'finance.partners.index', 'buyers.index',
        'settings.milk-prices.index', 'admin.audit.index'] as $routeName) {
        $html = $this->actingAs($this->admin)->get(route($routeName))->assertOk()->getContent();

        expect($html)->not->toMatch('/\b(finance|expenses|partners|buyers|pricing|audit|nav|app)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');
    }
})->with(['gu', 'hi']);

test('no Phase 6 or later route exists yet', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->toBeNull();
})->with([
    // Phase 6 — animals
    'animals.index',
    'animals.events.store',
    // Phase 7 — employees and payroll
    'employees.index',
    'payroll.index',
    // Phase 8 onwards
    'notifications.index',
    'reports.index',
]);
