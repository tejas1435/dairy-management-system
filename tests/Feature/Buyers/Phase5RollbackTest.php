<?php

use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\CreateBuyerBalanceAdjustment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\UpdateChannelSale;
use App\Actions\Settlements\CancelBuyerSettlement;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\BalanceAdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\SettlementStatus;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\BuyerSettlement;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkSale;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;

/*
 * Atomicity for the Phase 5 writes.
 *
 * The same approach as the Phase 4 rollback file: failures are injected with Eloquent
 * model events registered inside the test, so no production code knows about them and
 * no test-only failure switch ships.
 *
 * Settlement finalization is the write that matters most here. It freezes four figures
 * and posts a receivable adjustment, and a settlement that finalized without its
 * adjustment would claim an agreed amount while the buyer's balance still reflected the
 * system figure — a discrepancy that reconciles with nothing and that nobody finds
 * until the next statement arrives.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '100000.00');
    $this->cash = $accounts['cash'];

    $this->farm = $this->business->primaryFarm();

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);
    $this->outstanding = app(BuyerOutstandingService::class);

    $this->actingAs(superAdmin());

    $this->deliver = function (string $date, string $quantity, string $rate = '72.00') {
        seedProductionFor($this->farm, $date, cow: '1000.000', buffalo: '1000.000');

        return app(RecordChannelSale::class)->handle(
            buyer: $this->mandali,
            source: SaleSource::MandaliDelivery,
            date: $date,
            shift: Shift::Morning,
            milkType: MilkType::Cow,
            quantity: $quantity,
            rate: $rate,
            attributes: ['fat_percentage' => '4.50', 'snf_percentage' => '8.50'],
        );
    };

    // 100.000 L at 72.00 = 7,200.00 across September.
    $this->september = function (): void {
        ($this->deliver)('2026-09-05', '50.000');
        ($this->deliver)('2026-09-20', '50.000');
    };
});

/** Makes the next insert of the given model throw. */
function failOnCreatingBuyerRecord(string $modelClass): void
{
    $modelClass::creating(function (): never {
        throw new RuntimeException('Injected failure');
    });
}

/** Makes the next update of the given model throw. */
function failOnUpdatingBuyerRecord(string $modelClass): void
{
    $modelClass::updating(function (): never {
        throw new RuntimeException('Injected failure');
    });
}

afterEach(function (): void {
    // Model event listeners are static, so they must not leak into other tests.
    foreach ([
        AuditLog::class,
        BuyerBalanceAdjustment::class,
        BuyerPayment::class,
        BuyerSettlement::class,
        FinancialLedgerEntry::class,
        MilkSale::class,
    ] as $model) {
        $model::flushEventListeners();
    }

    // Re-register the guards the models rely on.
    AuditLog::bootTraits();
    FinancialLedgerEntry::bootTraits();
});

/*
|--------------------------------------------------------------------------
| A. Finalization: the case that matters most
|--------------------------------------------------------------------------
*/

test('finalization rolls back completely when its adjustment fails', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');

    failOnCreatingBuyerRecord(BuyerBalanceAdjustment::class);

    expect(fn () => app(FinalizeBuyerSettlement::class)->handle($settlement))
        ->toThrow(RuntimeException::class);

    $settlement->refresh();

    /*
     * The settlement is still a draft with no frozen figures. Had the snapshots
     * survived without the adjustment, the settlement would claim ₹7,500.00 was agreed
     * while the balance still said ₹7,200.00, and the difference would exist nowhere.
     */
    expect($settlement->status)->toBe(SettlementStatus::Draft)
        ->and($settlement->milk_quantity)->toBeNull()
        ->and($settlement->expected_amount)->toBeNull()
        ->and($settlement->difference)->toBeNull()
        ->and($settlement->finalized_at)->toBeNull()
        ->and(BuyerBalanceAdjustment::query()->count())->toBe(0);

    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

test('finalization rolls back completely when its audit record fails', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');

    $audits = AuditLog::query()->count();

    failOnCreatingBuyerRecord(AuditLog::class);

    expect(fn () => app(FinalizeBuyerSettlement::class)->handle($settlement))
        ->toThrow(RuntimeException::class);

    // An agreed settlement nobody can trace is not an improvement on no settlement.
    expect($settlement->refresh()->status)->toBe(SettlementStatus::Draft)
        ->and($settlement->expected_amount)->toBeNull()
        ->and(BuyerBalanceAdjustment::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);

    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

test('a settlement that fails to open leaves no row and no audit record', function () {
    ($this->september)();

    $audits = AuditLog::query()->count();

    failOnCreatingBuyerRecord(BuyerSettlement::class);

    expect(fn () => app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00'))
        ->toThrow(RuntimeException::class);

    expect(BuyerSettlement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);
});

test('withdrawing a settlement rolls back when its adjustment cannot be withdrawn', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');

    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $balance = $this->outstanding->outstandingFor($this->mandali);
    expectMoney($balance, '7500.00');

    // The withdrawal has to cancel the adjustment as well as the settlement.
    failOnUpdatingBuyerRecord(BuyerBalanceAdjustment::class);

    expect(fn () => app(CancelBuyerSettlement::class)->handle($settlement->refresh(), 'Opened in error'))
        ->toThrow(RuntimeException::class);

    $settlement->refresh();

    /*
     * Both stand. A cancelled settlement whose adjustment survived would leave the
     * difference in the balance for ever, attached to a settlement that no longer
     * claims it.
     */
    expect($settlement->status)->toBe(SettlementStatus::Finalized)
        ->and($settlement->cancelled_at)->toBeNull()
        ->and($settlement->adjustment)->not->toBeNull();

    expectMoney($this->outstanding->outstandingFor($this->mandali), $balance);
});

/*
|--------------------------------------------------------------------------
| B. Receipts and adjustments
|--------------------------------------------------------------------------
*/

test('a settlement receipt rolls back completely when the ledger credit fails', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);
    $settlement->refresh();

    $before = $this->cash->refresh()->balance();

    failOnCreatingBuyerRecord(FinancialLedgerEntry::class);

    expect(fn () => app(RecordBuyerPayment::class)->handle($this->mandali, [
        'amount' => '7200.00',
        'payment_date' => '2026-10-01',
        'financial_account_id' => $this->cash->id,
        'buyer_settlement_id' => $settlement->getKey(),
    ]))->toThrow(RuntimeException::class);

    expect(BuyerPayment::query()->count())->toBe(0)
        ->and(FinancialLedgerEntry::query()->count())->toBe(0)
        // And the settlement's derived status did not move on a receipt that never was.
        ->and($settlement->refresh()->status)->toBe(SettlementStatus::Finalized);

    expectMoney($this->cash->refresh()->balance(), $before);
    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

test('withdrawing a receipt rolls back completely when the reversal fails', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $payment = app(RecordBuyerPayment::class)->handle($this->mandali, [
        'amount' => '7200.00',
        'payment_date' => '2026-10-01',
        'financial_account_id' => $this->cash->id,
        'buyer_settlement_id' => $settlement->refresh()->getKey(),
    ]);

    expect($settlement->refresh()->status)->toBe(SettlementStatus::Paid);

    $balance = $this->cash->refresh()->balance();
    $entries = FinancialLedgerEntry::query()->count();

    failOnCreatingBuyerRecord(FinancialLedgerEntry::class);

    expect(fn () => app(CancelBuyerPayment::class)->handle($payment, 'Cheque returned unpaid'))
        ->toThrow(RuntimeException::class);

    // The receipt still stands, the settlement is still paid, and the account still
    // holds the money. A withdrawal without its reversal would be cash in the books
    // that nobody received.
    expect($payment->refresh()->status)->toBe(TransactionStatus::Active)
        ->and($settlement->refresh()->status)->toBe(SettlementStatus::Paid)
        ->and(FinancialLedgerEntry::query()->count())->toBe($entries);

    expectMoney($this->cash->refresh()->balance(), $balance);
});

test('an adjustment that fails to audit is not written', function () {
    ($this->september)();

    $audits = AuditLog::query()->count();

    failOnCreatingBuyerRecord(AuditLog::class);

    expect(fn () => app(CreateBuyerBalanceAdjustment::class)->handle(
        $this->mandali, BalanceAdjustmentDirection::Increase, '500.00',
        'Short weight recovered', '2026-09-30',
    ))->toThrow(RuntimeException::class);

    // An unexplained change to what somebody owes is exactly what this table exists to
    // prevent, so an adjustment without its audit record must not exist either.
    expect(BuyerBalanceAdjustment::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);

    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

/*
|--------------------------------------------------------------------------
| C. Sales
|--------------------------------------------------------------------------
*/

test('a channel sale that fails to audit allocates no milk and owes nothing', function () {
    seedProductionFor($this->farm, '2026-09-05', cow: '1000.000', buffalo: '1000.000');

    failOnCreatingBuyerRecord(AuditLog::class);

    expect(fn () => app(RecordChannelSale::class)->handle(
        buyer: $this->mandali,
        source: SaleSource::MandaliDelivery,
        date: '2026-09-05',
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: '50.000',
        rate: '72.00',
        attributes: ['fat_percentage' => '4.50'],
    ))->toThrow(RuntimeException::class);

    $result = app(CalculateMilkReconciliation::class)
        ->forShift($this->farm->id, '2026-09-05', Shift::Morning, MilkType::Cow);

    expect(MilkSale::query()->count())->toBe(0)
        ->and($result->sales->total)->toBe('0.000');

    expectMoney($this->outstanding->outstandingFor($this->mandali), '0.00');
});

test('a correction that fails leaves the sale exactly as it was', function () {
    $sale = ($this->deliver)('2026-09-05', '50.000');

    failOnCreatingBuyerRecord(AuditLog::class);

    expect(fn () => app(UpdateChannelSale::class)->handle($sale, [
        'quantity' => '60.000',
        'fat_percentage' => '5.00',
    ]))->toThrow(RuntimeException::class);

    $sale->refresh();

    expectMoney($sale->quantity, '50.000');
    expectMoney($sale->amount, '3600.00');
    expect((string) $sale->fat_percentage)->toBe('4.50');

    expectMoney($this->outstanding->outstandingFor($this->mandali), '3600.00');
});
