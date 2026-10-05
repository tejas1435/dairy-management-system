<?php

use App\Enums\FinancialAccountType;
use App\Models\Business;
use App\Models\FinancialAccount;
use App\Services\FinancialLedgerService;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    $this->manager = userWithPermissions(['finance.account.manage']);
});

/*
|--------------------------------------------------------------------------
| Schema guarantees
|--------------------------------------------------------------------------
*/

test('there is no stored current balance column anywhere', function () {
    // The whole design rests on the balance being derived. A column would let
    // it drift from the entries that produced it, with no way to tell which is
    // right.
    $columns = Schema::getColumnListing('financial_accounts');

    expect($columns)->not->toContain('current_balance')
        ->not->toContain('balance')
        ->not->toContain('running_balance')
        ->toContain('opening_balance');
});

test('money columns are decimal, not floating point', function () {
    $type = Schema::getColumnType('financial_accounts', 'opening_balance');

    expect($type)->toBe('decimal');
});

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

test('an authorised user can create an account', function () {
    $this->actingAs($this->manager)->post(route('finance.accounts.store'), [
        'name' => 'Petty Cash',
        'type' => FinancialAccountType::Cash->value,
        'opening_balance' => '2500.50',
        'is_active' => '1',
    ])->assertRedirect(route('finance.accounts.index'));

    $account = FinancialAccount::query()->where('name', 'Petty Cash')->firstOrFail();

    expect($account->type)->toBe(FinancialAccountType::Cash)
        ->and($account->business_id)->toBe($this->business->id);

    expectMoney($account->opening_balance, '2500.50');
});

test('the opening balance survives exactly, without float drift', function (string $amount) {
    $this->actingAs($this->manager)->post(route('finance.accounts.store'), [
        'name' => 'Account '.$amount,
        'type' => 'cash',
        'opening_balance' => $amount,
    ])->assertRedirect();

    expectMoney(FinancialAccount::query()->latest('id')->first()->opening_balance, $amount);
})->with(['0.01', '0.10', '1234.56', '99999999.99', '0.00']);

test('each supported account type is accepted', function (string $type) {
    $this->actingAs($this->manager)->post(route('finance.accounts.store'), [
        'name' => 'Account '.$type,
        'type' => $type,
        'opening_balance' => '0',
    ])->assertRedirect();

    expect(FinancialAccount::query()->where('type', $type)->exists())->toBeTrue();
})->with(['cash', 'bank', 'other']);

test('an unsupported account type is rejected', function () {
    $this->actingAs($this->manager)->post(route('finance.accounts.store'), [
        'name' => 'Crypto Wallet',
        'type' => 'bitcoin',
        'opening_balance' => '0',
    ])->assertSessionHasErrors('type');

    expect(FinancialAccount::query()->count())->toBe(0);
});

test('account names are unique within the business', function () {
    FinancialAccount::factory()->for($this->business)->create(['name' => 'Cash']);

    $this->actingAs($this->manager)->post(route('finance.accounts.store'), [
        'name' => 'Cash',
        'type' => 'cash',
        'opening_balance' => '0',
    ])->assertSessionHasErrors('name');
});

test('an account can be updated, deactivated and reactivated', function () {
    $account = FinancialAccount::factory()->for($this->business)->create(['name' => 'Old Name']);

    $this->actingAs($this->manager)->put(route('finance.accounts.update', $account), [
        'name' => 'New Name',
        'type' => 'bank',
        'opening_balance' => '100.00',
    ])->assertRedirect();

    expect($account->fresh()->name)->toBe('New Name')
        ->and($account->fresh()->type)->toBe(FinancialAccountType::Bank);

    $this->actingAs($this->manager)
        ->put(route('finance.accounts.status.update', $account), ['is_active' => 0])->assertRedirect();
    expect($account->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)
        ->put(route('finance.accounts.status.update', $account), ['is_active' => 1])->assertRedirect();
    expect($account->fresh()->is_active)->toBeTrue();
});

test('the opening balance is locked once the account has ledger entries', function () {
    // Restating it would silently restate every derived balance since.
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create();

    app(FinancialLedgerService::class)
        ->credit($account, '500.00', '2026-09-01', 'Something', 'k1');

    $this->actingAs($this->manager)->put(route('finance.accounts.update', $account), [
        'name' => $account->name,
        'type' => $account->type->value,
        'opening_balance' => '999999.00',
    ])->assertRedirect();

    expectMoney($account->fresh()->opening_balance, '1000.00');
    expectMoney($account->fresh()->balance(), '1500.00');
});

test('there is no route for deleting an account', function () {
    expect(app('router')->getRoutes()->getByName('finance.accounts.destroy'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Derived balance
|--------------------------------------------------------------------------
*/

test('the derived balance is opening plus credits minus debits', function () {
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create();

    $ledger = app(FinancialLedgerService::class);
    $ledger->credit($account, '500.00', '2026-09-01', 'in', 'k1');
    $ledger->debit($account, '300.00', '2026-09-02', 'out', 'k2');

    expectMoney($account->fresh()->balance(), '1200.00');
});

test('an account with no entries reads back its opening balance', function () {
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('750.25')->create();

    expectMoney($account->balance(), '750.25');
});

test('a zero opening balance with no movement is zero, not null', function () {
    $account = FinancialAccount::factory()->for($this->business)->create();

    expectMoney($account->balance(), '0.00');
});

test('the balance may go negative, which the books must represent rather than refuse', function () {
    // Overdrawing cash is a real situation; hiding it would make the ledger lie.
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('100.00')->create();

    app(FinancialLedgerService::class)->debit($account, '250.00', '2026-09-01', 'out', 'k1');

    expectMoney($account->fresh()->balance(), '-150.00');
});

test('the list query derives the same balance as the per-record method', function () {
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create();

    $ledger = app(FinancialLedgerService::class);
    $ledger->credit($account, '333.33', '2026-09-01', 'in', 'k1');
    $ledger->debit($account, '111.11', '2026-09-02', 'out', 'k2');

    $listed = FinancialAccount::query()->withBalance()->findOrFail($account->id);

    expectMoney($listed->loadedBalance(), '1222.22');
    expectMoney($account->fresh()->balance(), '1222.22');
});

test('a balance as at a date ignores later entries', function () {
    $account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create();

    $ledger = app(FinancialLedgerService::class);
    $ledger->credit($account, '500.00', '2026-09-01', 'in', 'k1');
    $ledger->credit($account, '700.00', '2026-10-01', 'later', 'k2');

    expectMoney($account->fresh()->balance('2026-09-30'), '1500.00');
    expectMoney($account->fresh()->balance(), '2200.00');
});

/*
|--------------------------------------------------------------------------
| Authorisation
|--------------------------------------------------------------------------
*/

test('account management is refused without finance.account.manage', function () {
    $user = userWithPermissions(['dashboard.view']);
    $account = FinancialAccount::factory()->for($this->business)->create();

    $this->actingAs($user)->get(route('finance.accounts.index'))->assertForbidden();
    $this->actingAs($user)->get(route('finance.accounts.show', $account))->assertForbidden();
    $this->actingAs($user)->post(route('finance.accounts.store'), [
        'name' => 'Sneaky', 'type' => 'cash', 'opening_balance' => '0',
    ])->assertForbidden();

    expect(FinancialAccount::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

test('an account from another business cannot be opened by id', function () {
    $other = Business::factory()->create();
    $foreign = FinancialAccount::factory()->for($other)->create();

    $this->actingAs($this->manager)->get(route('finance.accounts.show', $foreign))
        ->assertSessionHasErrors('account');
});
