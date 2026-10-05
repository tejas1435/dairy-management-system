<?php

use App\Actions\Buyers\CancelBuyerBalanceAdjustment;
use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\UpdateChannelSale;
use App\Actions\Settlements\CancelBuyerSettlement;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\AuditAction;
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
use App\Models\PaymentMethod;
use App\Services\Buyers\BuyerOutstandingService;
use Illuminate\Validation\ValidationException;

/*
 * The accounting edges of a settlement, as opposed to its lifecycle.
 *
 * MandaliSettlementTest covers the transitions — draft, finalize, cancel, overlap,
 * the period lock. This file covers what the money has to do at each of them: which
 * figure is actually due, what a posted field may and may not influence, that a
 * refused operation leaves nothing behind, and where the payment ceilings fall to the
 * paisa.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '100000.00');
    $this->cash = $accounts['cash'];
    $this->method = PaymentMethod::query()->where('code', PaymentMethod::CASH)->firstOrFail();

    $this->farm = $this->business->primaryFarm();

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);

    $this->outstanding = app(BuyerOutstandingService::class);

    $this->actingAs(superAdmin());

    /** A Mandali delivery, with the shift's production seeded for it. */
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

    $this->pay = function (string $amount, ?BuyerSettlement $settlement = null, string $date = '2026-10-05') {
        return app(RecordBuyerPayment::class)->handle($this->mandali, [
            'amount' => $amount,
            'payment_date' => $date,
            'financial_account_id' => $this->cash->id,
            'payment_method_id' => $this->method->id,
            'buyer_settlement_id' => $settlement?->getKey(),
        ]);
    };
});

/*
|--------------------------------------------------------------------------
| A. Which figure is due
|--------------------------------------------------------------------------
*/

test('the statement amount is what is due when the Mandali has sent one', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7000.00');

    app(FinalizeBuyerSettlement::class)->handle($settlement);
    $settlement->refresh();

    // The system expected 7,200.00 and the dairy says 7,000.00. What this period
    // settles is the agreed 7,000.00; the 200.00 it does not cover has already left
    // the balance as an adjustment, so it is not owed twice.
    expectMoney($settlement->expected_amount, '7200.00');
    expectMoney($settlement->amountDue(), '7000.00');
    expectMoney($settlement->remainingAmount(), '7000.00');
    expectMoney($this->outstanding->outstandingFor($this->mandali), '7000.00');
});

test('the system figure is due when no statement was ever entered', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);
    $settlement->refresh();

    expect($settlement->hasStatement())->toBeFalse()
        ->and($settlement->difference)->toBeNull();
    expectMoney($settlement->amountDue(), '7200.00');
});

test('the amount remaining never goes negative', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    ($this->pay)('7200.00', $settlement->refresh());

    expectMoney($settlement->refresh()->remainingAmount(), '0.00');
    expect($settlement->status)->toBe(SettlementStatus::Paid);
});

test('a draft reports no amount due, because nothing has been agreed', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7000.00');

    // The statement amount was captured, but the expected figure is deliberately
    // absent until finalization freezes it — a draft agrees nothing.
    expect($settlement->expected_amount)->toBeNull()
        ->and($settlement->difference)->toBeNull()
        ->and($settlement->isDraft())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| B. The server owns every figure but the statement amount
|--------------------------------------------------------------------------
*/

test('posted snapshots are ignored and the server recalculates them', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');

    $this->put(route('mandalis.settlements.finalize', [$this->mandali, $settlement]), [
        'statement_amount' => '7000.00',
        // All four of these are the server's to compute. None may be accepted.
        'milk_quantity' => '9999.000',
        'expected_amount' => '1.00',
        'difference' => '0.00',
        'status' => SettlementStatus::Paid->value,
    ])->assertRedirect();

    $settlement->refresh();

    expectMoney($settlement->milk_quantity, '100.000');
    expectMoney($settlement->expected_amount, '7200.00');
    // 7,000.00 − 7,200.00.
    expectMoney($settlement->difference, '-200.00');
    expect($settlement->status)->toBe(SettlementStatus::Finalized);
});

test('the expected amount follows the sales as they stand at finalization', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');

    // A late delivery, recorded after the draft was opened. The draft previewed
    // 7,200.00; what gets frozen is what the period actually holds.
    ($this->deliver)('2026-09-28', '10.000', '71.00');

    app(FinalizeBuyerSettlement::class)->handle($settlement);

    // 7,200.00 + 710.00.
    expectMoney($settlement->refresh()->expected_amount, '7910.00');
    expectMoney($settlement->milk_quantity, '110.000');
});

test('a sale outside the period is not counted however close it falls', function () {
    ($this->september)();

    // The day before the period and the day after it.
    ($this->deliver)('2026-08-31', '20.000');
    ($this->deliver)('2026-10-01', '20.000');

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    expectMoney($settlement->refresh()->milk_quantity, '100.000');
    expectMoney($settlement->expected_amount, '7200.00');
});

test('a sale date cannot be edited, so no sale can be moved into a settled period', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $october = ($this->deliver)('2026-10-10', '30.000');

    /*
     * The correction path has no date in it at all — not refused, absent. A sale is
     * identified by the shift it was collected in, and allowing its date to move
     * would silently take milk out of one day's reconciliation and a settled period's
     * total and put it in another's.
     */
    app(UpdateChannelSale::class)->handle($october, [
        'quantity' => '31.000',
        'sale_date' => '2026-09-15',
        'shift' => Shift::Evening->value,
    ]);

    $october->refresh();

    expect($october->sale_date->toDateString())->toBe('2026-10-10')
        ->and($october->shift)->toBe(Shift::Morning);
    expectMoney($october->quantity, '31.000');

    // And the settled period is untouched by it.
    expectMoney($settlement->refresh()->milk_quantity, '100.000');
});

/*
|--------------------------------------------------------------------------
| C. A refused operation leaves nothing behind
|--------------------------------------------------------------------------
*/

test('an overlapping settlement is refused without being created', function () {
    ($this->september)();

    app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');

    expect(fn () => app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-15', '2026-10-15', '500.00'))
        ->toThrow(ValidationException::class);

    expect(BuyerSettlement::query()->where('buyer_id', $this->mandali->id)->count())->toBe(1);
});

test('a refused finalization changes nothing about the settlement', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7000.00');

    app(CancelBuyerSettlement::class)->handle($settlement, 'Opened for the wrong period');

    expect(fn () => app(FinalizeBuyerSettlement::class)->handle($settlement->refresh()))
        ->toThrow(ValidationException::class);

    $settlement->refresh();

    expect($settlement->status)->toBe(SettlementStatus::Cancelled)
        ->and($settlement->finalized_at)->toBeNull()
        ->and($settlement->expected_amount)->toBeNull()
        // No adjustment was posted on the way to the refusal.
        ->and(BuyerBalanceAdjustment::query()->where('buyer_id', $this->mandali->id)
            ->where('status', TransactionStatus::Active->value)->count())->toBe(0);

    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

test('a second finalization neither duplicates the adjustment nor moves the balance', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');

    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $after = $this->outstanding->outstandingFor($this->mandali);
    $adjustments = BuyerBalanceAdjustment::query()->where('buyer_id', $this->mandali->id)->count();

    expect(fn () => app(FinalizeBuyerSettlement::class)->handle($settlement->refresh()))
        ->toThrow(ValidationException::class);

    expect(BuyerBalanceAdjustment::query()->where('buyer_id', $this->mandali->id)->count())->toBe($adjustments);
    expectMoney($this->outstanding->outstandingFor($this->mandali), $after);
    // 7,200.00 + 300.00.
    expectMoney($after, '7500.00');
});

test('a refused payment leaves no receipt and no account movement', function () {
    ($this->september)();

    $before = $this->cash->refresh()->balance();

    expect(fn () => ($this->pay)('7200.01'))->toThrow(ValidationException::class);

    expect(BuyerPayment::query()->where('buyer_id', $this->mandali->id)->count())->toBe(0);
    expectMoney($this->cash->refresh()->balance(), $before);
});

/*
|--------------------------------------------------------------------------
| D. The payment ceilings, to the paisa
|--------------------------------------------------------------------------
*/

test('a payment may reach the outstanding exactly and not one paisa further', function () {
    ($this->deliver)('2026-09-05', '33.333', '85.00');

    // 33.333 × 85.00 = 2,833.305, half-up to 2,833.31.
    expectMoney($this->outstanding->outstandingFor($this->mandali), '2833.31');

    expect(fn () => ($this->pay)('2833.32'))->toThrow(ValidationException::class);

    ($this->pay)('2833.31');

    expectMoney($this->outstanding->outstandingFor($this->mandali), '0.00');
});

test('a settlement payment may reach what the settlement owes and not one paisa further', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7000.00');
    app(FinalizeBuyerSettlement::class)->handle($settlement);
    $settlement->refresh();

    ($this->pay)('6999.99', $settlement);

    expect($settlement->refresh()->status)->toBe(SettlementStatus::PartiallyPaid);
    expectMoney($settlement->remainingAmount(), '0.01');

    expect(fn () => ($this->pay)('0.02', $settlement))->toThrow(ValidationException::class);

    ($this->pay)('0.01', $settlement);

    expect($settlement->refresh()->status)->toBe(SettlementStatus::Paid);
    expectMoney($settlement->remainingAmount(), '0.00');
});

test('an unlinked payment is still capped by the whole outstanding', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    ($this->pay)('7000.00');

    // 7,200.00 owed in total, 7,000.00 received against nothing in particular.
    expect(fn () => ($this->pay)('200.01'))->toThrow(ValidationException::class);
    ($this->pay)('200.00');

    expectMoney($this->outstanding->outstandingFor($this->mandali), '0.00');
});

/*
|--------------------------------------------------------------------------
| E. Linked and unlinked receipts are different things
|--------------------------------------------------------------------------
*/

test('an unlinked payment reduces the balance and moves no settlement status', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    ($this->pay)('7200.00');

    $settlement->refresh();

    // The money arrived, but nobody said what it was for. Guessing would be wrong in
    // the one case that matters: a Mandali with two open settlements.
    expect($settlement->status)->toBe(SettlementStatus::Finalized)
        ->and($settlement->paidAmount())->toBe('0.00');
    expectMoney($settlement->remainingAmount(), '7200.00');
    expectMoney($this->outstanding->outstandingFor($this->mandali), '0.00');
});

test('a receipt against one settlement does not pay another', function () {
    ($this->september)();
    ($this->deliver)('2026-10-05', '50.000');

    $september = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($september);

    $october = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-10-01', '2026-10-31');
    app(FinalizeBuyerSettlement::class)->handle($october);

    ($this->pay)('7200.00', $september->refresh(), '2026-11-01');

    expect($september->refresh()->status)->toBe(SettlementStatus::Paid)
        ->and($october->refresh()->status)->toBe(SettlementStatus::Finalized);
    expectMoney($october->remainingAmount(), '3600.00');
});

test('withdrawing a linked receipt moves the settlement status back', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $payment = ($this->pay)('7200.00', $settlement->refresh());

    expect($settlement->refresh()->status)->toBe(SettlementStatus::Paid);

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque returned unpaid');

    expect($settlement->refresh()->status)->toBe(SettlementStatus::Finalized);
    expectMoney($settlement->paidAmount(), '0.00');
    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

/*
|--------------------------------------------------------------------------
| F. The adjustment history stays readable
|--------------------------------------------------------------------------
*/

test('the settlement screen shows its adjustment and keeps a withdrawn one in the history', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $adjustment = $settlement->refresh()->adjustment;

    expect($adjustment)->not->toBeNull();
    expectMoney($adjustment->amount, '300.00');

    $this->get(route('mandalis.settlements.show', [$this->mandali, $settlement]))
        ->assertOk()
        ->assertSee(__('buyers.settlement.difference_recorded'))
        ->assertSee('300.00');

    app(CancelBuyerBalanceAdjustment::class)->handle($adjustment, 'Agreed to be a clerical error');

    // Withdrawn, not deleted: the settlement still shows what was once posted
    // against it (D21), and the balance is back to the system figure.
    $this->get(route('mandalis.settlements.show', [$this->mandali, $settlement]))
        ->assertOk()
        ->assertSee('300.00');

    expect($settlement->refresh()->adjustment)->toBeNull()
        ->and($settlement->adjustments()->count())->toBe(1);
    expectMoney($this->outstanding->outstandingFor($this->mandali), '7200.00');
});

/*
|--------------------------------------------------------------------------
| G. The audit trail of a settlement
|--------------------------------------------------------------------------
*/

test('every settlement transition is audited against its stable alias', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');

    app(FinalizeBuyerSettlement::class)->handle($settlement);
    app(CancelBuyerSettlement::class)->handle($settlement->refresh(), 'Agreed to re-open the period');

    $logs = AuditLog::query()
        ->where('auditable_type', 'buyer_settlement')
        ->where('auditable_id', $settlement->getKey())
        ->pluck('action')
        // The column is cast to the enum, so compare values rather than instances.
        ->map(fn ($action): string => $action instanceof AuditAction ? $action->value : (string) $action);

    // Opened, finalized and withdrawn: three decisions, three records.
    expect($logs->all())->toContain(AuditAction::Created->value)
        ->toContain(AuditAction::Cancelled->value)
        ->and($logs->count())->toBeGreaterThanOrEqual(3);

    /*
     * The alias, never the class name (D10). A stored `App\Models\BuyerSettlement`
     * would break every historical record the day the class moved.
     */
    expect(AuditLog::query()->pluck('auditable_type')->unique()->all())
        ->not->toContain(BuyerSettlement::class)
        ->toContain('buyer_settlement')
        ->toContain('buyer_balance_adjustment');
});

/*
|--------------------------------------------------------------------------
| H. The settlement list
|--------------------------------------------------------------------------
*/

test('the paid total is the same figure whether the receipts are loaded or queried', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($settlement);
    $settlement->refresh();

    // Three receipts that do not divide evenly, plus one that is withdrawn.
    ($this->pay)('1200.01', $settlement);
    ($this->pay)('2400.02', $settlement);
    $withdrawn = ($this->pay)('500.00', $settlement);
    ($this->pay)('0.03', $settlement);

    app(CancelBuyerPayment::class)->handle($withdrawn, 'Entered twice');

    /*
     * The profile and the settlement list eager-load the receipts so a list of a
     * year's settlements does not cost a query per row, and `paidAmount()` reads the
     * loaded relation when there is one. The two paths must agree exactly — the
     * in-memory one sums decimal strings through bcmath rather than through
     * `Collection::sum()`, which would add floats.
     */
    $queried = BuyerSettlement::query()->whereKey($settlement->getKey())->firstOrFail();

    $loaded = BuyerSettlement::query()
        ->whereKey($settlement->getKey())
        ->with(['payments' => fn ($q) => $q->where('status', TransactionStatus::Active->value)])
        ->firstOrFail();

    expect($loaded->relationLoaded('payments'))->toBeTrue()
        ->and($queried->relationLoaded('payments'))->toBeFalse();

    // 1,200.01 + 2,400.02 + 0.03, with the withdrawn 500.00 excluded by both paths.
    expectMoney($queried->paidAmount(), '3600.06');
    expectMoney($loaded->paidAmount(), '3600.06');
    expectMoney($loaded->remainingAmount(), $queried->remainingAmount());
});

test('the settlement list filters by status', function () {
    ($this->september)();
    ($this->deliver)('2026-10-05', '20.000');

    $draft = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-10-01', '2026-10-31');

    $finalized = app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-09-01', '2026-09-30');
    app(FinalizeBuyerSettlement::class)->handle($finalized);

    $url = route('mandalis.settlements.index', $this->mandali);

    // Unfiltered: both periods.
    $this->get($url)->assertOk()
        ->assertSee($draft->periodLabel())
        ->assertSee($finalized->refresh()->periodLabel());

    $this->get($url.'?status='.SettlementStatus::Draft->value)->assertOk()
        ->assertSee($draft->periodLabel())
        ->assertDontSee($finalized->periodLabel());

    $this->get($url.'?status='.SettlementStatus::Finalized->value)->assertOk()
        ->assertSee($finalized->periodLabel())
        ->assertDontSee($draft->periodLabel());

    // A status that is not one is refused rather than ignored.
    $this->get($url.'?status=settled')->assertSessionHasErrors('status');
});

test('a settlement difference of zero records no adjustment to withdraw later', function () {
    ($this->september)();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-09-01', '2026-09-30', '7200.00');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $settlement->refresh();

    expectMoney($settlement->difference, '0.00');
    expect($settlement->hasDifference())->toBeFalse()
        ->and($settlement->adjustments()->count())->toBe(0);

    $this->get(route('mandalis.settlements.show', [$this->mandali, $settlement]))
        ->assertOk()
        ->assertSee(__('buyers.settlement.no_difference'));
});
