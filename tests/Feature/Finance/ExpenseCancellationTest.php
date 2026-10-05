<?php

use App\Actions\Expenses\CancelExpense;
use App\Actions\Expenses\CreateExpense;
use App\Enums\AuditAction;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialLedgerEntry;
use App\Models\FundingAllocation;
use App\Models\Partner;
use App\Services\PartnerLedgerService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '50000.00');
    $this->cash = $accounts['cash'];

    $this->category = ExpenseCategory::query()->where('code', 'animal_feed')->firstOrFail();
    $this->partner = Partner::factory()->for($this->business)->create(['name' => 'Partner A']);

    $this->actor = superAdmin();
    $this->actingAs($this->actor);

    // The canonical split: 10,000 = partner 7,000 + cash 3,000.
    $this->expense = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-01',
        'amount' => '10000.00',
        'description' => 'Feed',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '7000.00'],
        ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '3000.00'],
    ]);

    $this->cancel = app(CancelExpense::class);
});

test('cancelling preserves the expense and its split while reversing only the account share', function () {
    expectMoney($this->cash->fresh()->balance(), '47000.00');

    $this->cancel->handle($this->expense, 'Duplicate entry');

    $expense = $this->expense->fresh();

    // History intact.
    expect(Expense::query()->count())->toBe(1)
        ->and($expense->status)->toBe(TransactionStatus::Cancelled)
        ->and($expense->cancelled_at)->not->toBeNull()
        ->and($expense->cancelled_by)->toBe($this->actor->id)
        ->and($expense->cancellation_reason)->toBe('Duplicate entry')
        // Allocations are never deleted.
        ->and(FundingAllocation::query()->count())->toBe(2);

    expectMoney($expense->amount, '10000.00');

    // The original debit stands; one credit reversal joins it.
    expect(FinancialLedgerEntry::query()->count())->toBe(2)
        ->and(FinancialLedgerEntry::query()->where('direction', 'debit')->count())->toBe(1)
        ->and(FinancialLedgerEntry::query()->where('direction', 'credit')->count())->toBe(1);

    $reversal = FinancialLedgerEntry::query()->whereNotNull('reverses_entry_id')->firstOrFail();
    expectMoney($reversal->amount, '3000.00');

    /*
     * Nothing was reversed for the partner's 7,000, because nothing was ever
     * posted for it. A reversal there would credit the business with money it
     * never received.
     */
    expect(FinancialLedgerEntry::query()->where('amount', '7000.00')->exists())->toBeFalse();

    // Cash is back where it started.
    expectMoney($this->cash->fresh()->balance(), '50000.00');
});

test('a cancelled expense stops counting towards active totals', function () {
    expectMoney(Expense::query()->active()->sum('amount'), '10000.00');

    $this->cancel->handle($this->expense, 'Duplicate entry');

    expectMoney(Expense::query()->active()->sum('amount'), '0.00');
    // But the row is still there to be found.
    expect(Expense::query()->count())->toBe(1);
});

test('a cancelled expense drops out of the partner ledger', function () {
    $ledger = app(PartnerLedgerService::class);
    expectMoney($ledger->total($this->partner), '7000.00');

    $this->cancel->handle($this->expense, 'Duplicate entry');

    expectMoney($ledger->total($this->partner), '0.00');
    expect($ledger->entries($this->partner))->toBeEmpty();

    // And the bulk total used by the list agrees.
    expectMoney($ledger->totalsFor(Partner::query()->get())[$this->partner->id], '0.00');
});

test('cancellation is audited with the reason', function () {
    $this->cancel->handle($this->expense, 'Wrong supplier');

    $log = AuditLog::query()->where('auditable_type', 'expense')
        ->where('action', AuditAction::Cancelled->value)->firstOrFail();

    expect($log->new_values['cancellation_reason'])->toBe('Wrong supplier')
        ->and($log->user_id)->toBe($this->actor->id);
});

test('cancelling twice is refused and adds no second reversal', function () {
    $this->cancel->handle($this->expense, 'First');

    expect(fn () => $this->cancel->handle($this->expense->fresh(), 'Second'))
        ->toThrow(ValidationException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('auditable_type', 'expense')
            ->where('action', AuditAction::Cancelled->value)->count())->toBe(1);

    expectMoney($this->cash->fresh()->balance(), '50000.00');
});

test('cancellation requires a non-empty reason', function () {
    expect(fn () => $this->cancel->handle($this->expense, '  '))->toThrow(ValidationException::class);

    expect($this->expense->fresh()->status)->toBe(TransactionStatus::Active);
    expectMoney($this->cash->fresh()->balance(), '47000.00');
});

test('cancelling through the form requires the reason and the permission', function () {
    $this->put(route('finance.expenses.cancel', $this->expense), ['cancellation_reason' => ''])
        ->assertSessionHasErrors('cancellation_reason');

    expect($this->expense->fresh()->status)->toBe(TransactionStatus::Active);

    $unauthorised = userWithPermissions(['expense.view']);
    $this->actingAs($unauthorised)
        ->put(route('finance.expenses.cancel', $this->expense), ['cancellation_reason' => 'Nope'])
        ->assertForbidden();

    expect($this->expense->fresh()->status)->toBe(TransactionStatus::Active);
});

test('cancelling an expense funded only by partners touches no account at all', function () {
    $partnerOnly = app(CreateExpense::class)->handle([
        'expense_category_id' => $this->category->id,
        'expense_date' => '2026-09-02',
        'amount' => '4000.00',
        'description' => 'Partner-funded only',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '4000.00'],
    ]);

    $entriesBefore = FinancialLedgerEntry::query()->count();

    $this->cancel->handle($partnerOnly, 'Not needed');

    expect(FinancialLedgerEntry::query()->count())->toBe($entriesBefore)
        ->and($partnerOnly->fresh()->status)->toBe(TransactionStatus::Cancelled);

    expectMoney($this->cash->fresh()->balance(), '47000.00');
});

/*
|--------------------------------------------------------------------------
| Posted-expense edit policy (docs/DECISIONS.md D23)
|--------------------------------------------------------------------------
*/

test('descriptive fields of a posted expense can be corrected', function () {
    $other = ExpenseCategory::query()->where('code', 'medicine')->firstOrFail();

    $this->put(route('finance.expenses.update', $this->expense), [
        'expense_category_id' => $other->id,
        'description' => 'Corrected description',
        'payee_name' => 'Corrected payee',
        'notes' => 'A note',
    ])->assertRedirect();

    $expense = $this->expense->fresh();

    expect($expense->description)->toBe('Corrected description')
        ->and($expense->payee_name)->toBe('Corrected payee')
        ->and($expense->expense_category_id)->toBe($other->id);
});

test('the amount, date and funding of a posted expense cannot be changed through the form', function () {
    /*
     * These decided the ledger entries already posted. Letting a form change
     * them would leave the expense, its allocations and the account balance
     * describing three different realities. Correction means cancel and
     * re-enter, which keeps both versions in the audit trail.
     */
    $this->put(route('finance.expenses.update', $this->expense), [
        'expense_category_id' => $this->category->id,
        'description' => 'Feed',
        // Fields the request does not accept.
        'amount' => '999999.00',
        'expense_date' => '2030-01-01',
        'allocations' => [
            ['source_type' => 'financial_account', 'source_id' => $this->cash->id, 'amount' => '999999.00'],
        ],
    ])->assertRedirect();

    $expense = $this->expense->fresh();

    expectMoney($expense->amount, '10000.00');
    expect($expense->expense_date->toDateString())->toBe('2026-09-01')
        ->and(FundingAllocation::query()->count())->toBe(2);

    expectMoney($expense->allocatedAmount(), '10000.00');
    expectMoney($this->cash->fresh()->balance(), '47000.00');
});

test('a cancelled expense cannot be edited', function () {
    $this->cancel->handle($this->expense, 'Cancelled');

    $this->get(route('finance.expenses.edit', $this->expense))->assertSessionHasErrors('status');

    $this->put(route('finance.expenses.update', $this->expense), [
        'expense_category_id' => $this->category->id,
        'description' => 'Trying to edit a cancelled expense',
    ])->assertSessionHasErrors('status');

    expect($this->expense->fresh()->description)->toBe('Feed');
});

test('an expense from another business cannot be cancelled by id', function () {
    $other = Business::factory()->create();
    $foreign = Expense::factory()->for($other)->create([
        'expense_category_id' => $this->category->id,
    ]);

    $this->put(route('finance.expenses.cancel', $foreign), ['cancellation_reason' => 'Nope'])
        ->assertSessionHasErrors('expense');

    expect($foreign->fresh()->status)->toBe(TransactionStatus::Active);
});
