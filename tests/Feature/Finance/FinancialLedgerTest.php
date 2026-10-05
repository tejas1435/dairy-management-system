<?php

use App\Enums\LedgerDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Partner;
use App\Services\FinancialLedgerService;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    $this->ledger = app(FinancialLedgerService::class);
    $this->account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create(['name' => 'Cash']);
});

/*
|--------------------------------------------------------------------------
| A. Credit
|--------------------------------------------------------------------------
*/

test('a credit creates exactly one entry and increases the balance by exactly that amount', function () {
    $reference = Partner::factory()->for($this->business)->create();

    $entry = $this->ledger->credit(
        account: $this->account,
        amount: '5000.00',
        date: '2026-09-01',
        description: 'Contribution',
        idempotencyKey: 'partner_contribution:1',
        reference: $reference,
    );

    expect(FinancialLedgerEntry::query()->count())->toBe(1)
        ->and($entry->direction)->toBe(LedgerDirection::Credit)
        ->and($entry->reference_type)->toBe('partner')
        ->and($entry->reference_id)->toBe($reference->id);

    expectMoney($entry->amount, '5000.00');
    expectMoney($this->account->fresh()->balance(), '6000.00');
});

/*
|--------------------------------------------------------------------------
| B. Debit
|--------------------------------------------------------------------------
*/

test('a debit creates exactly one entry and decreases the balance by exactly that amount', function () {
    $this->ledger->debit($this->account, '300.00', '2026-09-01', 'Feed', 'expense_funding:1');

    expect(FinancialLedgerEntry::query()->count())->toBe(1);
    expectMoney($this->account->fresh()->balance(), '700.00');
});

test('the balance is opening plus credits minus debits, to the paisa', function () {
    $this->ledger->credit($this->account, '500.00', '2026-09-01', 'c', 'k1');
    $this->ledger->debit($this->account, '300.00', '2026-09-02', 'd', 'k2');
    $this->ledger->credit($this->account, '0.55', '2026-09-03', 'c', 'k3');
    $this->ledger->debit($this->account, '0.05', '2026-09-04', 'd', 'k4');

    // 1000.00 + 500.00 - 300.00 + 0.55 - 0.05
    expectMoney($this->account->fresh()->balance(), '1200.50');
});

test('a zero or negative posting is refused', function (string $amount) {
    expect(fn () => $this->ledger->credit($this->account, $amount, '2026-09-01', 'x', 'k'.$amount))
        ->toThrow(RuntimeException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(0);
})->with(['0.00', '-1.00', '-0.01']);

/*
|--------------------------------------------------------------------------
| C. Idempotency
|--------------------------------------------------------------------------
*/

test('posting the same domain effect twice produces one entry and one balance change', function () {
    $key = FinancialLedgerService::key('partner_contribution', 42);

    $first = $this->ledger->credit($this->account, '5000.00', '2026-09-01', 'Contribution', $key);
    $second = $this->ledger->credit($this->account, '5000.00', '2026-09-01', 'Contribution', $key);

    expect(FinancialLedgerEntry::query()->count())->toBe(1)
        // The repeat returns the existing entry rather than failing.
        ->and($second->id)->toBe($first->id);

    expectMoney($this->account->fresh()->balance(), '6000.00');
});

test('the unique index catches a duplicate that slips past the pre-check', function () {
    $key = FinancialLedgerService::key('expense_funding', 7);

    $this->ledger->debit($this->account, '100.00', '2026-09-01', 'Feed', $key);

    // Simulates the race: two requests both find no existing row and both
    // insert. The database must reject the loser.
    expect(fn () => FinancialLedgerEntry::create([
        'financial_account_id' => $this->account->id,
        'entry_date' => '2026-09-01',
        'direction' => LedgerDirection::Debit->value,
        'amount' => '100.00',
        'description' => 'Feed again',
        'idempotency_key' => $key,
    ]))->toThrow(QueryException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(1);
    expectMoney($this->account->fresh()->balance(), '900.00');
});

/*
|--------------------------------------------------------------------------
| D. Different effects must not collide
|--------------------------------------------------------------------------
*/

test('two different operations with identical amount, date and account both post', function () {
    // Same money, same day, same account -- but genuinely two payments.
    $this->ledger->debit($this->account, '250.00', '2026-09-01', 'Feed', FinancialLedgerService::key('expense_funding', 1));
    $this->ledger->debit($this->account, '250.00', '2026-09-01', 'Medicine', FinancialLedgerService::key('expense_funding', 2));

    expect(FinancialLedgerEntry::query()->count())->toBe(2);
    expectMoney($this->account->fresh()->balance(), '500.00');
});

test('the same sequence number under different purposes does not collide', function () {
    $this->ledger->credit($this->account, '10.00', '2026-09-01', 'a', FinancialLedgerService::key('partner_contribution', 1));
    $this->ledger->debit($this->account, '10.00', '2026-09-01', 'b', FinancialLedgerService::key('expense_funding', 1));

    expect(FinancialLedgerEntry::query()->count())->toBe(2);
    expectMoney($this->account->fresh()->balance(), '1000.00');
});

/*
|--------------------------------------------------------------------------
| E. Immutability
|--------------------------------------------------------------------------
*/

test('a posted entry cannot be updated', function () {
    $entry = $this->ledger->debit($this->account, '100.00', '2026-09-01', 'Feed', 'k1');

    expect(fn () => $entry->update(['amount' => '1.00']))->toThrow(RuntimeException::class);

    expectMoney($entry->fresh()->amount, '100.00');
});

test('a posted entry cannot be deleted', function () {
    $entry = $this->ledger->debit($this->account, '100.00', '2026-09-01', 'Feed', 'k1');

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(1);
});

test('no route exists for editing or deleting a ledger entry', function () {
    $names = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName())->filter();

    expect($names->filter(fn ($n) => str_contains((string) $n, 'ledger'))->all())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| F. Reversal
|--------------------------------------------------------------------------
*/

test('reversing a debit leaves the original and posts one opposing credit', function () {
    $original = $this->ledger->debit($this->account, '3000.00', '2026-09-01', 'Feed', 'expense_funding:9');
    expectMoney($this->account->fresh()->balance(), '-2000.00');

    $reversal = $this->ledger->reverse($original, 'Cancelled: Feed', '2026-09-10');

    expect(FinancialLedgerEntry::query()->count())->toBe(2)
        ->and($reversal->direction)->toBe(LedgerDirection::Credit)
        ->and($reversal->reverses_entry_id)->toBe($original->id)
        ->and($reversal->description)->toBe('Cancelled: Feed')
        // The original is untouched, not zeroed.
        ->and($original->fresh()->direction)->toBe(LedgerDirection::Debit);

    expectMoney($original->fresh()->amount, '3000.00');
    expectMoney($reversal->amount, '3000.00');

    // Net effect back to the opening balance.
    expectMoney($this->account->fresh()->balance(), '1000.00');
});

test('a reversal inherits the original reference so it stays traceable', function () {
    $partner = Partner::factory()->for($this->business)->create();

    $original = $this->ledger->credit(
        $this->account, '500.00', '2026-09-01', 'Contribution', 'partner_contribution:5', $partner
    );

    $reversal = $this->ledger->reverse($original, 'Cancelled');

    expect($reversal->reference_type)->toBe('partner')
        ->and($reversal->reference_id)->toBe($partner->id);
});

/*
|--------------------------------------------------------------------------
| G. Double reversal
|--------------------------------------------------------------------------
*/

test('reversing twice returns the existing reversal instead of posting another', function () {
    $original = $this->ledger->debit($this->account, '3000.00', '2026-09-01', 'Feed', 'expense_funding:9');

    $first = $this->ledger->reverse($original, 'Cancelled');
    $second = $this->ledger->reverse($original, 'Cancelled again');

    expect($second->id)->toBe($first->id)
        ->and(FinancialLedgerEntry::query()->count())->toBe(2);

    expectMoney($this->account->fresh()->balance(), '1000.00');
});

test('the database refuses a second reversal of the same entry', function () {
    $original = $this->ledger->debit($this->account, '100.00', '2026-09-01', 'Feed', 'k1');
    $this->ledger->reverse($original, 'Cancelled');

    // Bypassing the service entirely: the unique index must still hold.
    expect(fn () => FinancialLedgerEntry::create([
        'financial_account_id' => $this->account->id,
        'entry_date' => '2026-09-02',
        'direction' => LedgerDirection::Credit->value,
        'amount' => '100.00',
        'description' => 'Sneaky second reversal',
        'idempotency_key' => 'something-else-entirely',
        'reverses_entry_id' => $original->id,
    ]))->toThrow(QueryException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(2);
});

test('a reversal cannot itself be reversed', function () {
    $original = $this->ledger->debit($this->account, '100.00', '2026-09-01', 'Feed', 'k1');
    $reversal = $this->ledger->reverse($original, 'Cancelled');

    expect(fn () => $this->ledger->reverse($reversal, 'Un-cancel'))->toThrow(RuntimeException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| H. Reversing everything for a record
|--------------------------------------------------------------------------
*/

test('reversing all entries for a record covers each one exactly once', function () {
    $partner = Partner::factory()->for($this->business)->create();

    $this->ledger->debit($this->account, '100.00', '2026-09-01', 'a', 'k1', $partner);
    $this->ledger->debit($this->account, '200.00', '2026-09-01', 'b', 'k2', $partner);
    // An unrelated entry that must not be touched.
    $this->ledger->debit($this->account, '50.00', '2026-09-01', 'other', 'k3');

    expectMoney($this->account->fresh()->balance(), '650.00');

    $reversals = $this->ledger->reverseAllFor($partner, 'Cancelled');

    expect($reversals)->toHaveCount(2);
    // 1000 - 350 + 300 = 950, the unrelated 50 debit still standing.
    expectMoney($this->account->fresh()->balance(), '950.00');

    // Running it again adds nothing.
    $again = $this->ledger->reverseAllFor($partner, 'Cancelled again');
    expect(FinancialLedgerEntry::query()->count())->toBe(5);
    expectMoney($this->account->fresh()->balance(), '950.00');
});
