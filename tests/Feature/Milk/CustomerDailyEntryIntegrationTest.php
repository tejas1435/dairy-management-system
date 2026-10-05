<?php

use App\Actions\Buyers\RecordBuyerPayment;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\MilkUsage;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;

/*
 * Enter Once, Update Everywhere.
 *
 * The specification's promise for this screen is that nobody re-keys a delivery
 * into finance (MASTER_SPEC section 21). These tests follow a quantity typed into
 * the grid all the way out to reconciliation, the customer ledger and the
 * outstanding balance, and check that it arrives exactly once.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->customer = directCustomer($this->business, [MilkType::Cow], ['name' => 'Rajesh Patel']);

    $this->engine = app(CalculateMilkReconciliation::class);
    $this->outstanding = app(BuyerOutstandingService::class);
    $this->ledger = app(CustomerLedgerService::class);

    $this->actingAs(superAdmin());

    $this->save = fn (?string $morning, ?string $evening = null, ?int $buyerId = null) => $this->postJson(
        route('milk.customer-entry.store'),
        ['date' => $this->date, 'rows' => [[
            'buyer_id' => $buyerId ?? $this->customer->id,
            'milk_type' => 'cow',
            'morning' => $morning,
            'evening' => $evening,
        ]]],
    );
});

/*
|--------------------------------------------------------------------------
| A. Reconciliation
|--------------------------------------------------------------------------
*/

test('a day entered on the grid reaches the reconciliation screen', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '20.000']);

    MilkUsage::factory()->for($this->farm)->create([
        'usage_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '2.000',
    ]);

    // Two customers, 12.500 litres between them.
    $other = directCustomer($this->business, [MilkType::Cow]);

    $this->postJson(route('milk.customer-entry.store'), [
        'date' => $this->date,
        'rows' => [
            ['buyer_id' => $this->customer->id, 'milk_type' => 'cow', 'morning' => '7.500', 'evening' => null],
            ['buyer_id' => $other->id, 'milk_type' => 'cow', 'morning' => '5.000', 'evening' => null],
        ],
    ])->assertOk();

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->production)->toBe('20.000')
        ->and($result->usageTotal)->toBe('2.000')
        ->and($result->sales->total)->toBe('12.500')
        ->and($result->allocated)->toBe('14.500')
        ->and($result->remaining)->toBe('5.500');
});

test('the grid feeds the direct customer channel and no other', function () {
    ($this->save)('4.000')->assertOk();

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->subsystemExists)->toBeTrue()
        ->and($result->sales->forChannel(SalesChannel::DIRECT_CUSTOMER))->toBe('4.000')
        ->and($result->sales->forChannel(SalesChannel::MANDALI))->toBe('0.000');
});

test('re-saving a day does not count the same delivery twice', function () {
    ($this->save)('4.000')->assertOk();
    ($this->save)('4.000')->assertOk();
    ($this->save)('4.000')->assertOk();

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->total)->toBe('4.000')
        ->and(MilkSale::query()->active()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| B. Ledger and outstanding
|--------------------------------------------------------------------------
*/

test('a grid entry becomes a receivable with no manual posting', function () {
    // 1.500 + 2.000 litres at 70.00 is 245.00.
    ($this->save)('1.500', '2.000')->assertOk();

    $breakdown = $this->outstanding->breakdownFor($this->customer);

    expectMoney($breakdown['sales'], '245.00');
    expectMoney($breakdown['payments'], '0.00');
    expectMoney($breakdown['outstanding'], '245.00');

    // Nothing was posted by hand: every rupee traces to a sale.
    expect(MilkSale::query()->active()->count())->toBe(2);
});

test('the ledger shows the day with its morning and evening split', function () {
    ($this->save)('1.500', '2.000')->assertOk();

    $statement = $this->ledger->statement($this->customer, $this->date, $this->date);
    $sales = $statement['rows']->where('kind', 'sale');

    expect($sales)->toHaveCount(1);

    $row = $sales->first();

    expect(Quantity::of($row->morningQuantity))->toBe('1.500')
        ->and(Quantity::of($row->eveningQuantity))->toBe('2.000')
        ->and(Quantity::of($row->totalQuantity))->toBe('3.500');
    expectMoney($row->amount, '245.00');
    expectMoney($statement['closing'], '245.00');
});

test('correcting a quantity moves the outstanding to the corrected figure', function () {
    ($this->save)('1.500')->assertOk();
    expectMoney($this->outstanding->breakdownFor($this->customer)['outstanding'], '105.00');

    ($this->save)('1.000')->assertOk();

    // Derived from the corrected canonical sale, with no stale 1.500 anywhere.
    expectMoney($this->outstanding->breakdownFor($this->customer)['outstanding'], '70.00');

    expect(MilkSale::query()->active()->count())->toBe(1)
        ->and(Quantity::of(MilkSale::query()->value('quantity')))->toBe('1.000');

    // And the change is in the history.
    expect(AuditLog::query()->where('auditable_type', 'milk_sale')->where('action', 'updated')->count())
        ->toBeGreaterThan(0);
});

test('removing a delivery removes its money and its milk, and keeps its history', function () {
    ($this->save)('1.500')->assertOk();

    $saleId = MilkSale::query()->value('id');

    ($this->save)(null)->assertOk();

    expectMoney($this->outstanding->breakdownFor($this->customer)['outstanding'], '0.00');

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);
    expect($result->sales->total)->toBe('0.000');

    // The row and its reason survive, and so does the audit trail.
    $sale = MilkSale::query()->whereKey($saleId)->firstOrFail();

    expect($sale->status)->toBe(TransactionStatus::Cancelled)
        ->and($sale->cancellation_reason)->not->toBeEmpty()
        ->and(AuditLog::query()->where('auditable_type', 'milk_sale')->where('action', 'cancelled')->count())
        ->toBe(1);
});

test('re-entering after a removal restores exactly one delivery everywhere', function () {
    ($this->save)('1.500')->assertOk();
    $saleId = MilkSale::query()->value('id');

    ($this->save)(null)->assertOk();
    ($this->save)('1.250')->assertOk();

    expect(MilkSale::query()->count())->toBe(1)
        ->and(MilkSale::query()->value('id'))->toBe($saleId);

    expectMoney($this->outstanding->breakdownFor($this->customer)['outstanding'], '87.50');

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);
    expect($result->sales->total)->toBe('1.250');

    $statement = $this->ledger->statement($this->customer, $this->date, $this->date);
    expect($statement['rows']->where('kind', 'sale'))->toHaveCount(1);
});

test('a payment against a grid-entered day settles it exactly', function () {
    ($this->save)('1.500', '2.000')->assertOk();

    $accounts = seedAccounts($this->business);

    app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date,
        'amount' => '245.00',
        'financial_account_id' => $accounts['cash']->id,
        'payment_method_id' => PaymentMethod::query()->value('id'),
    ]);

    expectMoney($this->outstanding->breakdownFor($this->customer)['outstanding'], '0.00');

    // A paisa more would have been refused.
    expect($this->outstanding->wouldOverpay($this->customer, '0.01'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| C. Nothing is written twice
|--------------------------------------------------------------------------
*/

test('one typed quantity produces one sale, one ledger row and one audit record', function () {
    ($this->save)('2.000')->assertOk();

    expect(MilkSale::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('auditable_type', 'milk_sale')->where('action', 'created')->count())->toBe(1);

    $statement = $this->ledger->statement($this->customer, $this->date, $this->date);

    expect($statement['rows']->where('kind', 'sale'))->toHaveCount(1);
});

test('a sale never posts to the financial ledger, because a receivable is not cash', function () {
    ($this->save)('2.000')->assertOk();

    /*
     * The customer owes money; none has moved. Posting a sale into an account
     * ledger would show cash the business does not have — the credit appears when
     * the payment does, which is the Phase 4 payment action's job.
     */
    expect(FinancialLedgerEntry::query()->count())->toBe(0);
});
