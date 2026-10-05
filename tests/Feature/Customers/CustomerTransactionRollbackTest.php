<?php

use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Customers\SaveDirectCustomer;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\CustomerPause;
use App\Models\CustomerPreference;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkSale;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;

/*
 * Atomicity for the Phase 4 writes.
 *
 * Failures are injected with Eloquent model events registered inside the test, so no
 * production code knows about them and no test-only failure switch ships.
 *
 * The property under test is the same each time: a write touching more than one table
 * either happens completely or not at all. The payment is the one that matters most —
 * a payment without its ledger credit would reduce what the customer owes while the
 * cash never appeared in the books.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '1000.00');
    $this->cash = $accounts['cash'];

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00');
    seedProductionFor($this->farm, $this->date);

    $this->customer = directCustomer($this->business, [MilkType::Cow]);
    $this->outstanding = app(BuyerOutstandingService::class);

    $this->actingAs(superAdmin());
});

/** Makes the next insert of the given model throw. */
function failOnCreatingCustomerRecord(string $modelClass): void
{
    $modelClass::creating(function (): never {
        throw new RuntimeException('Injected failure');
    });
}

/**
 * Disarms an injected failure, for a test that carries on afterwards.
 *
 * The listener is static and would otherwise still be armed for the rest of the test,
 * so the second half would fail for the wrong reason.
 */
function stopFailing(string $modelClass): void
{
    $modelClass::flushEventListeners();
    $modelClass::bootTraits();
}

afterEach(function (): void {
    // Model event listeners are static, so they must not leak into other tests.
    AuditLog::flushEventListeners();
    Buyer::flushEventListeners();
    BuyerPayment::flushEventListeners();
    CustomerPause::flushEventListeners();
    CustomerPreference::flushEventListeners();
    FinancialLedgerEntry::flushEventListeners();
    MilkSale::flushEventListeners();

    // Re-register the guards the models rely on.
    AuditLog::bootTraits();
    FinancialLedgerEntry::bootTraits();
});

/*
|--------------------------------------------------------------------------
| A. The payment: the case that matters most
|--------------------------------------------------------------------------
*/

test('a payment rolls back completely when the ledger credit fails', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    failOnCreatingCustomerRecord(FinancialLedgerEntry::class);

    expect(fn () => app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(RuntimeException::class);

    /*
     * Nothing survives. A payment without its credit would show the customer as
     * settled while the money never reached an account — a discrepancy nobody finds
     * until a reconciliation.
     */
    expect(BuyerPayment::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'buyer_payment')->count())->toBe(0)
        ->and($this->outstanding->outstandingFor($this->customer))->toBe('700.00');

    expectMoney($this->cash->fresh()->balance(), '1000.00');
});

test('a payment rolls back completely when the audit write fails', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(RuntimeException::class);

    expect(BuyerPayment::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->where('reference_type', 'buyer_payment')->count())->toBe(0);

    expectMoney($this->cash->fresh()->balance(), '1000.00');
});

test('cancelling a payment rolls back and the credit stands', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    $payment = app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expectMoney($this->cash->fresh()->balance(), '1700.00');

    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(CancelBuyerPayment::class)->handle($payment, 'A reason that will not survive'))
        ->toThrow(RuntimeException::class);

    $payment->refresh();

    // Still active, still credited, and the outstanding still settled.
    expect($payment->status)->toBe(TransactionStatus::Active)
        ->and($payment->cancelled_at)->toBeNull()
        ->and(FinancialLedgerEntry::query()
            ->where('reference_type', 'buyer_payment')->count())->toBe(1);

    expectMoney($this->cash->fresh()->balance(), '1700.00');
});

/*
|--------------------------------------------------------------------------
| B. The sale
|--------------------------------------------------------------------------
*/

test('a sale rolls back completely when the audit write fails', function () {
    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000'))
        ->toThrow(RuntimeException::class);

    expect(MilkSale::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_sale')->count())->toBe(0)
        ->and($this->outstanding->outstandingFor($this->customer))->toBe('0.00');
});

test('a failed sale leaves the reconciliation exactly as it was', function () {
    $engine = app(CalculateMilkReconciliation::class);

    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');

    $before = $engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    failOnCreatingCustomerRecord(AuditLog::class);

    $other = directCustomer($this->business, [MilkType::Cow]);

    expect(fn () => app(SaveCustomerDailySale::class)
        ->handle($other, $this->date, Shift::Morning, MilkType::Cow, '2.000'))
        ->toThrow(RuntimeException::class);

    $after = $engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($after->sales->total)->toBe($before->sales->total)
        ->and($after->remaining)->toBe($before->remaining);
});

test('a failed quantity change leaves the earlier figures intact', function () {
    $sale = app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '5.000'))
        ->toThrow(RuntimeException::class);

    $sale->refresh();

    expect($sale->quantity)->toBe('2.000')
        ->and($sale->amount)->toBe('140.00');
});

/*
|--------------------------------------------------------------------------
| C. The customer and its preferences save together
|--------------------------------------------------------------------------
*/

test('a customer rolls back when its preferences fail', function () {
    /*
     * A customer created with no milk type is not usable and would sit in the list
     * looking complete, so the profile and its preferences are one operation.
     */
    failOnCreatingCustomerRecord(CustomerPreference::class);

    expect(fn () => app(SaveDirectCustomer::class)->create(
        ['name' => 'Half Saved'],
        ['cow' => ['is_active' => true, 'morning' => '1.000', 'evening' => '1.000']],
    ))->toThrow(RuntimeException::class);

    expect(Buyer::query()->where('name', 'Half Saved')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', 'buyer')
            ->where('action', 'created')->count())->toBe(0);
});

test('a customer update rolls back when the audit write fails', function () {
    $original = $this->customer->name;

    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(SaveDirectCustomer::class)->update($this->customer, ['name' => 'Renamed']))
        ->toThrow(RuntimeException::class);

    expect($this->customer->fresh()->name)->toBe($original);
});

/*
|--------------------------------------------------------------------------
| D. The pause
|--------------------------------------------------------------------------
*/

test('a pause rolls back when the audit write fails', function () {
    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(CreateCustomerPause::class)
        ->handle($this->customer, '2026-11-01', '2026-11-05', 'Away'))
        ->toThrow(RuntimeException::class);

    expect(CustomerPause::query()->count())->toBe(0);
});

test('a failed pause leaves the customer deliverable', function () {
    failOnCreatingCustomerRecord(AuditLog::class);

    expect(fn () => app(CreateCustomerPause::class)
        ->handle($this->customer, $this->date, $this->date, 'Away'))
        ->toThrow(RuntimeException::class);

    stopFailing(AuditLog::class);

    // The pause never happened, so a delivery is still possible — which is the
    // observable consequence of the rollback, not just the absent row.
    $sale = app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    expect($sale)->not->toBeNull()
        ->and(CustomerPause::query()->count())->toBe(0);
});
