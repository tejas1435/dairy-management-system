<?php

use App\Actions\Buyers\CancelBuyerBalanceAdjustment;
use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Actions\Milk\UpdateChannelSale;
use App\Actions\Pricing\SetMilkPrice;
use App\Actions\Settlements\CancelBuyerSettlement;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\BalanceAdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\SettlementStatus;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\BuyerSettlement;
use App\Models\FinancialLedgerEntry;
use App\Models\PaymentMethod;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\PriceResolver;
use App\Support\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/*
 * Mandali settlement.
 *
 * The month closes: what the system recorded is compared with what the dairy says it
 * owes, and the difference is accounted for explicitly. The specification is
 * unusually direct about the one thing not to do — "do not silently modify historical
 * milk rates" — so most of this file is about the figures being snapshots and the
 * difference becoming a visible adjustment rather than a quiet rewrite.
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
    $this->create = app(CreateBuyerSettlement::class);
    $this->finalize = app(FinalizeBuyerSettlement::class);
    $this->cancel = app(CancelBuyerSettlement::class);

    $this->actingAs(superAdmin());

    /** Records a Mandali delivery on a date, seeding production for it first. */
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

    // Two deliveries of 50.000 L at 72.00 = 7,200.00 across September.
    $this->septemberDeliveries = function (): void {
        ($this->deliver)('2026-09-05', '50.000');
        ($this->deliver)('2026-09-20', '50.000');
    };
});

/*
|--------------------------------------------------------------------------
| A. A draft is a working document
|--------------------------------------------------------------------------
*/

test('a draft settlement has no accounting effect at all', function () {
    ($this->septemberDeliveries)();

    $before = $this->outstanding->breakdownFor($this->mandali);

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7500.00');

    expect($settlement->status)->toBe(SettlementStatus::Draft)
        // Snapshots stay null: nothing has been agreed, and a zero would read as
        // "no milk" rather than "not established".
        ->and($settlement->milk_quantity)->toBeNull()
        ->and($settlement->expected_amount)->toBeNull()
        ->and($settlement->difference)->toBeNull();

    // No adjustment, and the balance is exactly where it was.
    expect(BuyerBalanceAdjustment::query()->count())->toBe(0);
    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], $before['outstanding']);
});

test('a draft can be opened with no statement at all', function () {
    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    expect($settlement->statement_amount)->toBeNull()
        ->and($settlement->hasStatement())->toBeFalse();
});

test('a draft statement amount can be edited, and a finalized one cannot', function () {
    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    $this->create->updateDraft($settlement, '7500.00', 'Statement received by post');

    expectMoney($settlement->refresh()->statement_amount, '7500.00');

    ($this->septemberDeliveries)();
    $this->finalize->handle($settlement);

    expect(fn () => $this->create->updateDraft($settlement->refresh(), '9999.00', null))
        ->toThrow(ValidationException::class);
});

test('a settlement belongs to a Mandali and nothing else', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    $vendor = Buyer::factory()->vendor($this->business)->create();

    foreach ([$customer, $vendor] as $buyer) {
        expect(fn () => $this->create->handle($buyer, '2026-09-01', '2026-09-30'))
            ->toThrow(ValidationException::class);
    }

    expect(BuyerSettlement::query()->count())->toBe(0);
});

test('a period cannot end before it starts', function () {
    expect(fn () => $this->create->handle($this->mandali, '2026-09-30', '2026-09-01'))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| B. The period's figures
|--------------------------------------------------------------------------
*/

test('finalization snapshots the quantity and the amount from the sales themselves', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    $settlement->refresh();

    expect($settlement->status)->toBe(SettlementStatus::Finalized)
        ->and(Quantity::of($settlement->milk_quantity))->toBe('100.000')
        ->and($settlement->finalized_at)->not->toBeNull();

    // 100.000 L at 72.00.
    expectMoney($settlement->expected_amount, '7200.00');
});

test('the expected amount uses the stored rates, not current prices', function () {
    ($this->deliver)('2026-09-05', '50.000', '72.00');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    // The business default changes afterwards. The settlement must not notice.
    app(SetMilkPrice::class)
        ->forBusiness($this->business, MilkType::Cow, '95.00', '2026-10-01');
    app(PriceResolver::class)->forget();

    $this->finalize->handle($settlement);

    expectMoney($settlement->refresh()->expected_amount, '3600.00');
});

test('only this Mandali active deliveries in the period are counted', function () {
    ($this->septemberDeliveries)();

    // Another Mandali, a vendor and a customer, all in the same period.
    $otherMandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Other Mandali']);
    seedProductionFor($this->farm, '2026-09-10', cow: '1000.000', buffalo: '1000.000');

    app(RecordChannelSale::class)->handle(
        buyer: $otherMandali, source: SaleSource::MandaliDelivery, date: '2026-09-10',
        shift: Shift::Morning, milkType: MilkType::Cow, quantity: '30.000', rate: '72.00',
        attributes: ['fat_percentage' => '4.50'],
    );

    // A delivery outside the period.
    ($this->deliver)('2026-10-05', '40.000');

    // And one inside it that gets cancelled.
    $cancelled = ($this->deliver)('2026-09-25', '20.000');
    app(CancelMilkSale::class)->handle($cancelled, 'Duplicate entry');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    // Only the two September deliveries to this Mandali: 100.000 L, 7,200.00.
    expect(Quantity::of($settlement->refresh()->milk_quantity))->toBe('100.000');
    expectMoney($settlement->expected_amount, '7200.00');
});

/*
|--------------------------------------------------------------------------
| C. The difference becomes an explicit adjustment
|--------------------------------------------------------------------------
*/

test('a statement above the expected amount raises the receivable', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7700.00');
    $this->finalize->handle($settlement);

    expectMoney($settlement->refresh()->difference, '500.00');

    $adjustment = BuyerBalanceAdjustment::query()->firstOrFail();

    expect($adjustment->direction)->toBe(BalanceAdjustmentDirection::Increase)
        ->and($adjustment->buyer_settlement_id)->toBe($settlement->id)
        ->and($adjustment->reason)->not->toBeEmpty();

    expectMoney($adjustment->amount, '500.00');

    // 7,200.00 of sales plus a 500.00 increase.
    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '7700.00');
    expectMoney($this->outstanding->breakdownFor($this->mandali)['adjustments'], '500.00');
});

test('a statement below the expected amount lowers the receivable', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '6900.00');
    $this->finalize->handle($settlement);

    expectMoney($settlement->refresh()->difference, '-300.00');

    $adjustment = BuyerBalanceAdjustment::query()->firstOrFail();

    expect($adjustment->direction)->toBe(BalanceAdjustmentDirection::Decrease);

    // The amount is stored positive; the direction carries the sign.
    expectMoney($adjustment->amount, '300.00');
    expect(bccomp(Quantity::money($adjustment->amount), '0.00', 2))->toBeGreaterThan(0);

    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '6900.00');
    expectMoney($this->outstanding->breakdownFor($this->mandali)['adjustments'], '-300.00');
});

test('no statement means no adjustment and the system figure is due', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    $settlement->refresh();

    expect($settlement->difference)->toBeNull()
        ->and($settlement->hasDifference())->toBeFalse()
        ->and(BuyerBalanceAdjustment::query()->count())->toBe(0);

    expectMoney($settlement->amountDue(), '7200.00');
    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '7200.00');
});

test('a statement equal to the expected amount creates no adjustment', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7200.00');
    $this->finalize->handle($settlement);

    expectMoney($settlement->refresh()->difference, '0.00');

    // A zero-rupee correction is noise in a ledger somebody has to read.
    expect(BuyerBalanceAdjustment::query()->count())->toBe(0)
        ->and($settlement->hasDifference())->toBeFalse();
});

test('no historical rate is rewritten by finalization', function () {
    $first = ($this->deliver)('2026-09-05', '50.000', '72.00');
    $second = ($this->deliver)('2026-09-20', '50.000', '72.00');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '6900.00');
    $this->finalize->handle($settlement);

    // The specification's explicit instruction: the deliveries are untouched.
    foreach ([$first, $second] as $sale) {
        $sale->refresh();

        expectMoney($sale->unit_rate, '72.00');
        expectMoney($sale->amount, '3600.00');
        expect(Quantity::of($sale->quantity))->toBe('50.000')
            ->and($sale->status)->toBe(TransactionStatus::Active);
    }
});

/*
|--------------------------------------------------------------------------
| D. Finalizing twice
|--------------------------------------------------------------------------
*/

test('a settlement cannot be finalized twice', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7700.00');
    $this->finalize->handle($settlement);

    expect(fn () => $this->finalize->handle($settlement->refresh()))->toThrow(ValidationException::class);

    // One adjustment, one receivable effect.
    expect(BuyerBalanceAdjustment::query()->count())->toBe(1);
    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '7700.00');
});

test('the database refuses a second active adjustment for one settlement', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7700.00');
    $this->finalize->handle($settlement);

    /*
     * The application already refuses a second finalization, but a retry racing the
     * status check would slip past it. The conditional unique index is the backstop,
     * so one disagreement cannot correct a receivable twice.
     */
    expect(fn () => BuyerBalanceAdjustment::query()->create([
        'buyer_id' => $this->mandali->id,
        'buyer_settlement_id' => $settlement->id,
        'adjustment_date' => '2026-09-30',
        'direction' => BalanceAdjustmentDirection::Increase->value,
        'amount' => '500.00',
        'reason' => 'Injected duplicate',
        'status' => TransactionStatus::Active->value,
    ]))->toThrow(QueryException::class);
});

test('a cancelled adjustment does not block a replacement for the same settlement', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7700.00');
    $this->finalize->handle($settlement);

    $adjustment = BuyerBalanceAdjustment::query()->firstOrFail();
    app(CancelBuyerBalanceAdjustment::class)->handle($adjustment, 'Wrong figure');

    // The conditional index only covers active rows, so a corrected one may follow.
    $replacement = BuyerBalanceAdjustment::query()->create([
        'buyer_id' => $this->mandali->id,
        'buyer_settlement_id' => $settlement->id,
        'adjustment_date' => '2026-09-30',
        'direction' => BalanceAdjustmentDirection::Increase->value,
        'amount' => '400.00',
        'reason' => 'Corrected difference',
        'status' => TransactionStatus::Active->value,
    ]);

    expect($replacement->exists)->toBeTrue()
        ->and(BuyerBalanceAdjustment::query()->active()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| E. Overlapping periods
|--------------------------------------------------------------------------
*/

test('two non-cancelled settlements may not overlap', function (string $start, string $end) {
    $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    expect(fn () => $this->create->handle($this->mandali, $start, $end))
        ->toThrow(ValidationException::class);

    expect(BuyerSettlement::query()->count())->toBe(1);
})->with([
    'identical' => ['2026-09-01', '2026-09-30'],
    'contained' => ['2026-09-10', '2026-09-20'],
    'overlapping the start' => ['2026-08-20', '2026-09-05'],
    'overlapping the end' => ['2026-09-25', '2026-10-10'],
    'containing' => ['2026-08-01', '2026-10-31'],
    'touching the last day' => ['2026-09-30', '2026-10-31'],
]);

test('adjacent periods are allowed', function () {
    $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $october = $this->create->handle($this->mandali, '2026-10-01', '2026-10-31');

    expect($october->exists)->toBeTrue()
        ->and(BuyerSettlement::query()->count())->toBe(2);
});

test('a cancelled settlement does not block its period being settled again', function () {
    $first = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->cancel->handle($first, 'Opened by mistake');

    $second = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    expect($second->exists)->toBeTrue()
        ->and($second->status)->toBe(SettlementStatus::Draft);
});

test('another Mandali may settle the same period', function () {
    $other = Buyer::factory()->mandali($this->business)->create(['name' => 'Other Mandali']);

    $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $theirs = $this->create->handle($other, '2026-09-01', '2026-09-30');

    expect($theirs->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| F. A finalized period is closed to sale corrections
|--------------------------------------------------------------------------
*/

test('a delivery inside a finalized settlement cannot be corrected', function () {
    $sale = ($this->deliver)('2026-09-05', '50.000');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '3600.00');
    $this->finalize->handle($settlement);

    expect(fn () => app(UpdateChannelSale::class)->handle($sale, ['quantity' => '40.000']))
        ->toThrow(ValidationException::class);

    expect(Quantity::of($sale->refresh()->quantity))->toBe('50.000');
});

test('a delivery inside a finalized settlement cannot be withdrawn', function () {
    $sale = ($this->deliver)('2026-09-05', '50.000');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    expect(fn () => app(CancelMilkSale::class)->handle($sale, 'Wrong quantity'))
        ->toThrow(ValidationException::class);

    expect($sale->refresh()->status)->toBe(TransactionStatus::Active);
});

test('a new delivery cannot be added to a finalized period', function () {
    ($this->deliver)('2026-09-05', '50.000');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    seedProductionFor($this->farm, '2026-09-15', cow: '1000.000', buffalo: '1000.000');

    expect(fn () => ($this->deliver)('2026-09-15', '10.000'))->toThrow(ValidationException::class);
});

test('withdrawing the settlement reopens the period for corrections', function () {
    $sale = ($this->deliver)('2026-09-05', '50.000');

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    $this->cancel->handle($settlement->refresh(), 'Figures disputed');

    // The documented order of operations: withdraw, correct, settle again.
    app(UpdateChannelSale::class)->handle($sale, ['quantity' => '40.000']);

    expect(Quantity::of($sale->refresh()->quantity))->toBe('40.000');
});

test('a draft settlement does not close the period', function () {
    $sale = ($this->deliver)('2026-09-05', '50.000');

    $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    // A draft has agreed nothing, so there is nothing to protect.
    app(UpdateChannelSale::class)->handle($sale, ['quantity' => '45.000']);

    expect(Quantity::of($sale->refresh()->quantity))->toBe('45.000');
});

test('a customer sale is unaffected by Mandali settlements', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    seedProductionFor($this->farm, '2026-09-05', cow: '1000.000', buffalo: '1000.000');

    $sale = app(SaveCustomerDailySale::class)
        ->handle($customer, '2026-09-05', Shift::Morning, MilkType::Cow, '2.000');

    ($this->deliver)('2026-09-05', '50.000');
    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    // The guard is scoped to the buyer, so Phase 4 behaviour is untouched.
    app(SaveCustomerDailySale::class)
        ->handle($customer, '2026-09-05', Shift::Morning, MilkType::Cow, '3.000');

    expect(Quantity::of($sale->refresh()->quantity))->toBe('3.000');
});

/*
|--------------------------------------------------------------------------
| G. Cancelling a settlement
|--------------------------------------------------------------------------
*/

test('withdrawing a settlement withdraws its adjustment with it', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30', '7700.00');
    $this->finalize->handle($settlement);

    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '7700.00');

    $this->cancel->handle($settlement->refresh(), 'Statement was for the wrong month');

    $settlement->refresh();
    $adjustment = BuyerBalanceAdjustment::query()->firstOrFail();

    expect($settlement->status)->toBe(SettlementStatus::Cancelled)
        ->and($settlement->cancellation_reason)->not->toBeEmpty()
        ->and($adjustment->status)->toBe(TransactionStatus::Cancelled);

    // The correction stops affecting the balance; the sales still stand.
    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '7200.00');
    expectMoney($this->outstanding->breakdownFor($this->mandali)['adjustments'], '0.00');
});

test('a settlement with an active payment cannot be withdrawn', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    app(RecordBuyerPayment::class)->handle($this->mandali, [
        'payment_date' => '2026-10-01',
        'amount' => '1000.00',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $settlement->id,
    ]);

    /*
     * A receipt is a record that cash arrived. Reversing it as a side effect of
     * re-doing paperwork would take money out of an account that still holds it.
     */
    expect(fn () => $this->cancel->handle($settlement->refresh(), 'Disputed'))
        ->toThrow(ValidationException::class);

    expect($settlement->refresh()->status)->not->toBe(SettlementStatus::Cancelled)
        ->and(BuyerPayment::query()->active()->count())->toBe(1);
});

test('withdrawing the payment first allows the settlement to be withdrawn', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    $payment = app(RecordBuyerPayment::class)->handle($this->mandali, [
        'payment_date' => '2026-10-01',
        'amount' => '1000.00',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $settlement->id,
    ]);

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque bounced');

    $this->cancel->handle($settlement->refresh(), 'Disputed');

    expect($settlement->refresh()->status)->toBe(SettlementStatus::Cancelled);
});

test('a cancelled settlement cannot be cancelled again or finalized', function () {
    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->cancel->handle($settlement, 'Opened by mistake');

    expect(fn () => $this->cancel->handle($settlement->refresh(), 'Again'))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->finalize->handle($settlement->refresh()))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| H. Payment status is derived
|--------------------------------------------------------------------------
*/

test('settlement payment status follows the receipts against it', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    expectMoney($settlement->refresh()->amountDue(), '7200.00');
    expect($settlement->status)->toBe(SettlementStatus::Finalized);

    $pay = fn (string $amount) => app(RecordBuyerPayment::class)->handle($this->mandali, [
        'payment_date' => '2026-10-01',
        'amount' => $amount,
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $settlement->id,
    ]);

    $first = $pay('3000.00');

    expect($settlement->refresh()->status)->toBe(SettlementStatus::PartiallyPaid);
    expectMoney($settlement->paidAmount(), '3000.00');
    expectMoney($settlement->remainingAmount(), '4200.00');

    $pay('4200.00');

    expect($settlement->refresh()->status)->toBe(SettlementStatus::Paid);
    expectMoney($settlement->remainingAmount(), '0.00');

    // Withdrawing a receipt moves the status back on its own.
    app(CancelBuyerPayment::class)->handle($first, 'Entered twice');

    expect($settlement->refresh()->status)->toBe(SettlementStatus::PartiallyPaid);
    expectMoney($settlement->paidAmount(), '4200.00');
});

test('a payment cannot exceed what a settlement still owes', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    expect(fn () => app(RecordBuyerPayment::class)->handle($this->mandali, [
        'payment_date' => '2026-10-01',
        'amount' => '7200.01',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $settlement->id,
    ]))->toThrow(ValidationException::class);

    expect(BuyerPayment::query()->count())->toBe(0);
});

test('a payment cannot be linked to a draft settlement', function () {
    ($this->septemberDeliveries)();

    $draft = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');

    // A draft has agreed no amount, so a receipt against it is money against nothing.
    expect(fn () => app(RecordBuyerPayment::class)->handle($this->mandali, [
        'payment_date' => '2026-10-01',
        'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $draft->id,
    ]))->toThrow(ValidationException::class);
});

test('a settlement payment belongs to the settlement buyer', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    $other = Buyer::factory()->mandali($this->business)->create(['name' => 'Other Mandali']);

    expect(fn () => app(RecordBuyerPayment::class)->handle($other, [
        'payment_date' => '2026-10-01',
        'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $settlement->id,
    ]))->toThrow(ValidationException::class);
});

test('a settlement payment credits its account exactly once', function () {
    ($this->septemberDeliveries)();

    $settlement = $this->create->handle($this->mandali, '2026-09-01', '2026-09-30');
    $this->finalize->handle($settlement);

    $payment = app(RecordBuyerPayment::class)->handle($this->mandali, [
        'payment_date' => '2026-10-01',
        'amount' => '3000.00',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
        'buyer_settlement_id' => $settlement->id,
    ]);

    $entries = FinancialLedgerEntry::query()
        ->where('reference_type', 'buyer_payment')
        ->where('reference_id', $payment->id)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->direction->value)->toBe('credit');

    expectMoney($entries->first()->amount, '3000.00');

    // And the buyer owes 3,000 less.
    expectMoney($this->outstanding->breakdownFor($this->mandali)['outstanding'], '4200.00');
});
