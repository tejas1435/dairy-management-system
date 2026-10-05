<?php

use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Farm;
use App\Models\FinancialAccount;
use App\Models\FundingAllocation;
use App\Models\MilkPriceRule;
use App\Models\Partner;
use App\Models\PartnerContribution;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Polymorphic columns must hold short stable aliases, never PHP class names
 * (docs/DECISIONS.md D10). A class name in the database ties years of business
 * records to today's namespace.
 */

test('every registered model resolves to its expected alias', function (string $alias, string $class) {
    expect(Relation::getMorphedModel($alias))->toBe($class);

    /** @var Model $model */
    $model = new $class;
    expect($model->getMorphClass())->toBe($alias);
})->with([
    ['user', User::class],
    ['business', Business::class],
    ['farm', Farm::class],
    ['role', Role::class],
    ['permission', Permission::class],
    ['financial_account', FinancialAccount::class],
    ['payment_method', PaymentMethod::class],
    ['partner', Partner::class],
    ['partner_contribution', PartnerContribution::class],
    ['expense_category', ExpenseCategory::class],
    ['expense', Expense::class],
    ['sales_channel', SalesChannel::class],
    ['buyer', Buyer::class],
    ['milk_price_rule', MilkPriceRule::class],
    ['buyer_price_rule', BuyerPriceRule::class],
]);

test('the map is enforced, so an unmapped model cannot silently persist a class name', function () {
    expect(Relation::requiresMorphMap())->toBeTrue();

    $unmapped = new class extends Model
    {
        protected $table = 'expenses';
    };

    // Asserted on the behaviour rather than the exception class, which Laravel
    // has moved between releases; what matters is that it refuses loudly.
    $thrown = null;

    try {
        $unmapped->getMorphClass();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('morph map');
});

test('no alias resolves to a php class name string', function () {
    foreach (array_keys(MorphMap::map()) as $alias) {
        expect($alias)
            ->not->toContain('\\')
            ->not->toStartWith('App')
            ->toMatch('/^[a-z][a-z0-9_]*$/');
    }
});

test('funding allocations persist aliases and resolve back to the right models', function () {
    $business = seedBusiness();
    seedPhase2Masters();

    $expense = Expense::factory()->for($business)->create([
        'expense_category_id' => ExpenseCategory::query()->firstOrFail()->id,
    ]);
    $partner = Partner::factory()->for($business)->create();
    $account = FinancialAccount::factory()->for($business)->create();

    $fromPartner = FundingAllocation::create([
        'payable_type' => $expense->getMorphClass(),
        'payable_id' => $expense->id,
        'source_type' => $partner->getMorphClass(),
        'source_id' => $partner->id,
        'amount' => '100.00',
    ]);

    $fromAccount = FundingAllocation::create([
        'payable_type' => $expense->getMorphClass(),
        'payable_id' => $expense->id,
        'source_type' => $account->getMorphClass(),
        'source_id' => $account->id,
        'amount' => '50.00',
    ]);

    // What is actually on disk, bypassing Eloquent.
    $rows = DB::table('funding_allocations')->orderBy('id')->get();

    expect($rows[0]->payable_type)->toBe('expense')
        ->and($rows[0]->source_type)->toBe('partner')
        ->and($rows[1]->source_type)->toBe('financial_account');

    // And they hydrate back to the right classes.
    expect($fromPartner->fresh()->source)->toBeInstanceOf(Partner::class)
        ->and($fromAccount->fresh()->source)->toBeInstanceOf(FinancialAccount::class)
        ->and($fromPartner->fresh()->payable)->toBeInstanceOf(Expense::class);
});

test('the funding source and payable lists only contain registered aliases', function () {
    foreach (array_merge(MorphMap::fundingSources(), MorphMap::fundingPayables()) as $alias) {
        expect(MorphMap::map())->toHaveKey($alias);
    }
});

test('the Phase 5 aliases are registered now that their models exist', function (string $alias) {
    expect(MorphMap::map())->toHaveKey($alias);
})->with(['buyer_settlement', 'buyer_balance_adjustment']);

test('no future phase alias is registered before its model exists', function (string $alias) {
    expect(MorphMap::map())->not->toHaveKey($alias);
})->with([
    // Phase 6
    'animal',
    'animal_event',
    // Phase 7
    'employee',
    'payroll_payment',
    'employee_loan_transaction',
]);

test('every Phase 3 milk model is registered, because they exist now', function (string $alias) {
    expect(MorphMap::map())->toHaveKey($alias);
})->with([
    'milk_production',
    'milk_usage',
    'milk_adjustment',
]);

test('every Phase 4 customer model is registered, because they exist now', function (string $alias) {
    expect(MorphMap::map())->toHaveKey($alias);
})->with([
    'customer_preference',
    'customer_pause',
    'milk_sale',
    'buyer_payment',
]);
