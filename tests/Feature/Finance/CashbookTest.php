<?php

use App\Models\Business;
use App\Models\FinancialAccount;
use App\Services\FinancialLedgerService;
use Illuminate\Pagination\Paginator;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->account = FinancialAccount::factory()->for($this->business)
        ->withOpeningBalance('1000.00')->create(['name' => 'Cash']);

    $this->ledger = app(FinancialLedgerService::class);
    $this->viewer = userWithPermissions(['finance.cashbook.view']);
});

/** Posts n alternating entries of 100 credit / 40 debit, one per day. */
function postSequence(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        $date = now()->setDate(2026, 9, 1)->addDays($i - 1)->toDateString();

        if ($i % 2 === 1) {
            test()->ledger->credit(test()->account, '100.00', $date, "credit {$i}", "c{$i}");
        } else {
            test()->ledger->debit(test()->account, '40.00', $date, "debit {$i}", "d{$i}");
        }
    }
}

test('an empty cashbook opens and closes at the opening balance', function () {
    $book = $this->ledger->cashbook($this->account);

    expect($book['rows'])->toBeEmpty();
    expectMoney($book['opening'], '1000.00');
    expectMoney($book['closing'], '1000.00');
});

test('the running balance is exact after every row', function () {
    $this->ledger->credit($this->account, '500.00', '2026-09-01', 'in', 'k1');
    $this->ledger->debit($this->account, '300.00', '2026-09-02', 'out', 'k2');
    $this->ledger->credit($this->account, '0.55', '2026-09-03', 'in', 'k3');

    $book = $this->ledger->cashbook($this->account);
    $rows = $book['rows'];

    expectMoney($book['opening'], '1000.00');
    expectMoney($rows[0]->running_balance, '1500.00');
    expectMoney($rows[1]->running_balance, '1200.00');
    expectMoney($rows[2]->running_balance, '1200.55');
    expectMoney($book['closing'], '1200.55');
});

test('rows come back in date then id order, deterministically', function () {
    // Two entries on the same day must still have a stable order.
    $this->ledger->credit($this->account, '10.00', '2026-09-02', 'second day first', 'k2');
    $this->ledger->credit($this->account, '20.00', '2026-09-01', 'first day', 'k1');
    $this->ledger->credit($this->account, '30.00', '2026-09-02', 'second day second', 'k3');

    $descriptions = $this->ledger->cashbook($this->account)['rows']
        ->map(fn ($row) => $row->entry->description)->all();

    expect($descriptions)->toBe(['first day', 'second day first', 'second day second']);
});

test('debits and credits land in their own columns', function () {
    $this->ledger->credit($this->account, '500.00', '2026-09-01', 'in', 'k1');
    $this->ledger->debit($this->account, '300.00', '2026-09-02', 'out', 'k2');

    $rows = $this->ledger->cashbook($this->account)['rows'];

    expect($rows[0]->entry->direction->value)->toBe('credit')
        ->and($rows[1]->entry->direction->value)->toBe('debit');
});

/*
|--------------------------------------------------------------------------
| Date filtering
|--------------------------------------------------------------------------
*/

test('a date range narrows the rows but carries the earlier history into the opening', function () {
    $this->ledger->credit($this->account, '500.00', '2026-08-15', 'before', 'k1');
    $this->ledger->credit($this->account, '200.00', '2026-09-10', 'inside', 'k2');
    $this->ledger->credit($this->account, '900.00', '2026-10-05', 'after', 'k3');

    $book = $this->ledger->cashbook($this->account, '2026-09-01', '2026-09-30');

    expect($book['rows'])->toHaveCount(1);

    // 1000 opening + 500 from August.
    expectMoney($book['opening'], '1500.00');
    expectMoney($book['closing'], '1700.00');
});

test('the closing figure of a full period matches the account balance', function () {
    postSequence(6);

    $book = $this->ledger->cashbook($this->account);

    expectMoney($book['closing'], $this->account->fresh()->balance());
});

/*
|--------------------------------------------------------------------------
| Pagination: the running balance must survive it
|--------------------------------------------------------------------------
*/

test('page two starts from the balance carried out of page one, not from the opening', function () {
    /*
     * The regression this exists for: restarting each page at the period's
     * opening balance. Every figure after page one would be wrong, and the
     * error grows with the size of the ledger.
     */
    postSequence(10);

    $first = $this->ledger->cashbook($this->account, perPage: 4);
    $lastOnFirstPage = $first['rows']->last()->running_balance;

    $this->get('/?page=2');
    request()->merge(['page' => 2]);
    Paginator::currentPageResolver(fn () => 2);

    $second = $this->ledger->cashbook($this->account, perPage: 4);

    expectMoney($second['brought_forward'], $lastOnFirstPage);
    // And the first row of page two continues from there.
    expectMoney(
        $second['rows']->first()->running_balance,
        bcadd($lastOnFirstPage, $second['rows']->first()->entry->signedAmount(), 2)
    );

    Paginator::currentPageResolver(fn () => 1);
});

test('the last page closes on the same figure as the unpaginated account balance', function () {
    postSequence(10);

    Paginator::currentPageResolver(fn () => 3);
    $last = $this->ledger->cashbook($this->account, perPage: 4);
    Paginator::currentPageResolver(fn () => 1);

    expect($last['paginator']->lastPage())->toBe(3);
    expectMoney($last['closing'], $this->account->fresh()->balance());
});

test('page one brings forward nothing', function () {
    postSequence(6);

    $book = $this->ledger->cashbook($this->account, perPage: 4);

    expectMoney($book['brought_forward'], $book['opening']);
});

test('pagination and a date filter combine without corrupting the balance', function () {
    $this->ledger->credit($this->account, '5000.00', '2026-08-01', 'before window', 'pre');
    postSequence(8);

    Paginator::currentPageResolver(fn () => 2);
    $page2 = $this->ledger->cashbook($this->account, '2026-09-01', '2026-09-30', perPage: 3);
    Paginator::currentPageResolver(fn () => 1);

    // Opening includes the August credit; brought forward adds the first page.
    expectMoney($page2['opening'], '6000.00');

    $page1 = $this->ledger->cashbook($this->account, '2026-09-01', '2026-09-30', perPage: 3);
    expectMoney($page2['brought_forward'], $page1['rows']->last()->running_balance);
});

/*
|--------------------------------------------------------------------------
| Through the screen
|--------------------------------------------------------------------------
*/

test('the cashbook page shows the derived figures', function () {
    $this->ledger->credit($this->account, '500.00', '2026-09-01', 'Contribution received', 'k1');

    $this->actingAs($this->viewer)
        ->get(route('finance.cashbook', ['account' => $this->account->id]))
        ->assertOk()
        ->assertSee('Contribution received')
        ->assertSee(__('finance.cashbook.opening_balance'))
        ->assertSee(__('finance.cashbook.closing_balance'));
});

test('the cashbook is refused without finance.cashbook.view', function () {
    $user = userWithPermissions(['dashboard.view']);

    $this->actingAs($user)->get(route('finance.cashbook'))->assertForbidden();
});

test('an account from another business cannot be opened in the cashbook', function () {
    $other = Business::factory()->create();
    $foreign = FinancialAccount::factory()->for($other)->create();

    $this->actingAs($this->viewer)
        ->get(route('finance.cashbook', ['account' => $foreign->id]))
        ->assertSessionHasErrors('account');
});

test('the cashbook offers no export buttons yet', function () {
    // Phase 9 owns exports; a button that does nothing is worse than none.
    $html = $this->actingAs($this->viewer)
        ->get(route('finance.cashbook', ['account' => $this->account->id]))
        ->getContent();

    expect($html)->not->toContain('export')
        ->not->toContain('.pdf')
        ->not->toContain('.xlsx');
});
