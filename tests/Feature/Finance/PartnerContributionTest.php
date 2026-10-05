<?php

use App\Actions\Expenses\CreateExpense;
use App\Actions\Partners\CancelPartnerContribution;
use App\Actions\Partners\CreatePartnerContribution;
use App\Enums\AuditAction;
use App\Enums\LedgerDirection;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerContribution;
use App\Models\PaymentMethod;
use App\Services\FinancialLedgerService;
use App\Services\PartnerLedgerService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, bankOpening: '0.00');
    $this->bank = $accounts['bank'];
    $this->cash = $accounts['cash'];

    $this->partner = Partner::factory()->for($this->business)->create(['name' => 'Partner A']);
    $this->method = PaymentMethod::query()->where('code', PaymentMethod::BANK_TRANSFER)->firstOrFail();

    $this->actingAs(superAdmin());
    $this->contribute = app(CreatePartnerContribution::class);
});

/*
|--------------------------------------------------------------------------
| Recording a contribution
|--------------------------------------------------------------------------
*/

test('a fifty thousand contribution credits the bank and shows up everywhere at once', function () {
    $contribution = $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01',
        'amount' => '50000.00',
        'financial_account_id' => $this->bank->id,
        'payment_method_id' => $this->method->id,
        'reference' => 'NEFT-9912',
    ]);

    // One contribution.
    expect(PartnerContribution::query()->count())->toBe(1)
        ->and($contribution->financial_account_id)->toBe($this->bank->id)
        ->and($contribution->payment_method_id)->toBe($this->method->id)
        ->and($contribution->status)->toBe(TransactionStatus::Active);
    expectMoney($contribution->amount, '50000.00');

    // One credit.
    expect(FinancialLedgerEntry::query()->count())->toBe(1);
    $entry = FinancialLedgerEntry::query()->firstOrFail();
    expect($entry->direction)->toBe(LedgerDirection::Credit)
        ->and($entry->financial_account_id)->toBe($this->bank->id)
        ->and($entry->reference_type)->toBe('partner_contribution')
        ->and($entry->reference_id)->toBe($contribution->id);
    expectMoney($entry->amount, '50000.00');

    // Account balance.
    expectMoney($this->bank->fresh()->balance(), '50000.00');
    expectMoney($this->cash->fresh()->balance(), '0.00');

    // Partner ledger.
    $ledger = app(PartnerLedgerService::class);
    expectMoney($ledger->total($this->partner), '50000.00');
    expect($ledger->entries($this->partner)->first()->type)->toBe('contribution');

    // Cashbook.
    $book = app(FinancialLedgerService::class)->cashbook($this->bank->fresh());
    expect($book['rows'])->toHaveCount(1);
    expectMoney($book['closing'], '50000.00');

    // Audit.
    expect(AuditLog::query()->where('auditable_type', 'partner_contribution')
        ->where('action', AuditAction::Created->value)->exists())->toBeTrue();
});

test('recording the same contribution twice creates two distinct contributions, not a doubled one', function () {
    // Two genuine payments of the same amount on the same day are legitimate.
    // Each gets its own id, so each gets its own idempotency key and its own
    // credit -- the guard protects against replaying ONE operation, not against
    // two real ones.
    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '1000.00',
        'financial_account_id' => $this->bank->id,
    ]);
    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '1000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    expect(PartnerContribution::query()->count())->toBe(2)
        ->and(FinancialLedgerEntry::query()->count())->toBe(2);

    expectMoney($this->bank->fresh()->balance(), '2000.00');
});

test('replaying the ledger effect of one contribution cannot double-credit', function () {
    $contribution = $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '5000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    // The same deterministic key the action used.
    app(FinancialLedgerService::class)->credit(
        account: $this->bank,
        amount: '5000.00',
        date: '2026-09-01',
        description: 'Replay',
        idempotencyKey: FinancialLedgerService::key('partner_contribution', $contribution->id),
        reference: $contribution,
    );

    expect(FinancialLedgerEntry::query()->count())->toBe(1);
    expectMoney($this->bank->fresh()->balance(), '5000.00');
});

test('an inactive partner cannot contribute', function () {
    $inactive = Partner::factory()->for($this->business)->inactive()->create();

    expect(fn () => $this->contribute->handle($inactive, [
        'contribution_date' => '2026-09-01', 'amount' => '100.00',
        'financial_account_id' => $this->bank->id,
    ]))->toThrow(ValidationException::class);

    expect(PartnerContribution::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0);
});

test('a contribution cannot be routed into another business account', function () {
    $other = Business::factory()->create();
    $foreign = FinancialAccount::factory()->for($other)->create();

    expect(fn () => $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '100.00',
        'financial_account_id' => $foreign->id,
    ]))->toThrow(ValidationException::class);

    expect(PartnerContribution::query()->count())->toBe(0);
});

test('a contribution into an inactive account is refused', function () {
    $inactive = FinancialAccount::factory()->for($this->business)->inactive()->create();

    expect(fn () => $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '100.00',
        'financial_account_id' => $inactive->id,
    ]))->toThrow(ValidationException::class);

    expect(PartnerContribution::query()->count())->toBe(0);
});

test('a zero or negative contribution is refused by the form', function (string $amount) {
    $this->post(route('finance.partners.contributions.store', $this->partner), [
        'contribution_date' => '2026-09-01',
        'amount' => $amount,
        'financial_account_id' => $this->bank->id,
    ])->assertSessionHasErrors('amount');

    expect(PartnerContribution::query()->count())->toBe(0);
})->with(['0', '0.00', '-100.00']);

/*
|--------------------------------------------------------------------------
| Cancellation
|--------------------------------------------------------------------------
*/

test('cancelling a contribution keeps everything and reverses the credit', function () {
    $contribution = $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '50000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    expectMoney($this->bank->fresh()->balance(), '50000.00');

    app(CancelPartnerContribution::class)->handle($contribution, 'Cheque bounced');

    $contribution->refresh();

    // The record survives with its metadata.
    expect(PartnerContribution::query()->count())->toBe(1)
        ->and($contribution->status)->toBe(TransactionStatus::Cancelled)
        ->and($contribution->cancelled_at)->not->toBeNull()
        ->and($contribution->cancelled_by)->not->toBeNull()
        ->and($contribution->cancellation_reason)->toBe('Cheque bounced');
    expectMoney($contribution->amount, '50000.00');

    // Original credit remains, one debit reversal added.
    expect(FinancialLedgerEntry::query()->count())->toBe(2)
        ->and(FinancialLedgerEntry::query()->where('direction', 'credit')->count())->toBe(1)
        ->and(FinancialLedgerEntry::query()->where('direction', 'debit')->count())->toBe(1);

    $reversal = FinancialLedgerEntry::query()->whereNotNull('reverses_entry_id')->firstOrFail();
    expectMoney($reversal->amount, '50000.00');

    // Net zero.
    expectMoney($this->bank->fresh()->balance(), '0.00');

    // Gone from the active partner ledger.
    expectMoney(app(PartnerLedgerService::class)->total($this->partner), '0.00');

    // Audited.
    expect(AuditLog::query()->where('auditable_type', 'partner_contribution')
        ->where('action', AuditAction::Cancelled->value)->exists())->toBeTrue();
});

test('cancelling twice adds no second reversal', function () {
    $contribution = $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '5000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    $action = app(CancelPartnerContribution::class);
    $action->handle($contribution, 'First');

    expect(fn () => $action->handle($contribution->fresh(), 'Second'))
        ->toThrow(ValidationException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', AuditAction::Cancelled->value)->count())->toBe(1);

    expectMoney($this->bank->fresh()->balance(), '0.00');
});

test('cancelling requires a reason', function () {
    $contribution = $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '5000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    expect(fn () => app(CancelPartnerContribution::class)->handle($contribution, '   '))
        ->toThrow(ValidationException::class);

    expect($contribution->fresh()->status)->toBe(TransactionStatus::Active);
});

test('the cancellation form requires a reason too', function () {
    $contribution = $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '5000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    $this->put(route('finance.contributions.cancel', $contribution), ['cancellation_reason' => ''])
        ->assertSessionHasErrors('cancellation_reason');

    expect($contribution->fresh()->status)->toBe(TransactionStatus::Active);
});

/*
|--------------------------------------------------------------------------
| Partner ledger derivation
|--------------------------------------------------------------------------
*/

test('the partner ledger combines contributions and partner-funded expenses', function () {
    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '50000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    app(CreateExpense::class)->handle([
        'expense_category_id' => ExpenseCategory::query()->where('code', 'animal_feed')->firstOrFail()->id,
        'expense_date' => '2026-09-05',
        'amount' => '7000.00',
        'description' => 'Feed paid by partner',
    ], [
        ['source_type' => 'partner', 'source_id' => $this->partner->id, 'amount' => '7000.00'],
    ]);

    $ledger = app(PartnerLedgerService::class);
    $entries = $ledger->entries($this->partner);

    expect($entries)->toHaveCount(2)
        // The two kinds are distinguishable, not just two amounts.
        ->and($entries->pluck('type')->sort()->values()->all())->toBe(['contribution', 'expense']);

    expectMoney($ledger->total($this->partner), '57000.00');
});

test('the partner ledger respects a date range', function () {
    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '1000.00',
        'financial_account_id' => $this->bank->id,
    ]);
    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-10-15', 'amount' => '2000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    $ledger = app(PartnerLedgerService::class);

    expectMoney($ledger->total($this->partner, '2026-09-01', '2026-09-30'), '1000.00');
    expectMoney($ledger->total($this->partner, '2026-10-01', '2026-10-31'), '2000.00');
    expectMoney($ledger->total($this->partner), '3000.00');
});

test('one partner ledger never shows another partners money', function () {
    $other = Partner::factory()->for($this->business)->create(['name' => 'Partner B']);

    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '1000.00',
        'financial_account_id' => $this->bank->id,
    ]);

    $ledger = app(PartnerLedgerService::class);

    expectMoney($ledger->total($this->partner), '1000.00');
    expectMoney($ledger->total($other), '0.00');
    expect($ledger->entries($other))->toBeEmpty();
});

test('the bulk totals used by the list agree with the per-partner totals', function () {
    $other = Partner::factory()->for($this->business)->create();

    $this->contribute->handle($this->partner, [
        'contribution_date' => '2026-09-01', 'amount' => '1500.00',
        'financial_account_id' => $this->bank->id,
    ]);
    $this->contribute->handle($other, [
        'contribution_date' => '2026-09-01', 'amount' => '2500.00',
        'financial_account_id' => $this->bank->id,
    ]);

    $ledger = app(PartnerLedgerService::class);
    $totals = $ledger->totalsFor(Partner::query()->get());

    expectMoney($totals[$this->partner->id], '1500.00');
    expectMoney($totals[$other->id], '2500.00');
});

test('there is no partner ledger table; the ledger is derived', function () {
    expect(Schema::hasTable('partner_ledger_entries'))->toBeFalse()
        ->and(Schema::hasTable('partner_ledgers'))->toBeFalse();

    expect(Schema::getColumnListing('partners'))
        ->not->toContain('balance')
        ->not->toContain('total_contributed');
});
