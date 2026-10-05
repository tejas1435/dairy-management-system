<?php

use App\Actions\Buyers\CancelBuyerBalanceAdjustment;
use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\CreateBuyerBalanceAdjustment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\BalanceAdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerSettlement;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkSale;
use App\Models\PaymentMethod;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/*
 * One outstanding engine for every channel.
 *
 *     outstanding = active sales + active receivable adjustments − active payments
 *
 * There is deliberately no MandaliOutstandingService or VendorOutstandingService: a
 * second copy of this arithmetic is a second copy to keep correct. These tests prove
 * the one implementation answers for all four channels, and that Phase 4's customers
 * still behave exactly as they did.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '100000.00');
    $this->cash = $accounts['cash'];
    $this->bank = $accounts['bank'];
    $this->method = PaymentMethod::query()->where('code', PaymentMethod::CASH)->firstOrFail();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '1000.000', buffalo: '1000.000');

    $this->outstanding = app(BuyerOutstandingService::class);
    $this->adjust = app(CreateBuyerBalanceAdjustment::class);

    $this->actingAs(superAdmin());

    $this->sell = fn (Buyer $buyer, SaleSource $source, string $quantity, string $rate) => app(RecordChannelSale::class)->handle(
        buyer: $buyer, source: $source, date: $this->date,
        shift: Shift::Morning, milkType: MilkType::Cow,
        quantity: $quantity, rate: $rate,
        attributes: $source->recordsMilkQuality() ? ['fat_percentage' => '4.50'] : [],
    );

    $this->pay = fn (Buyer $buyer, string $amount, ?int $accountId = null) => app(RecordBuyerPayment::class)->handle($buyer, [
        'payment_date' => $this->date,
        'amount' => $amount,
        'financial_account_id' => $accountId ?? $this->cash->id,
        'payment_method_id' => $this->method->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| A. One engine, every channel
|--------------------------------------------------------------------------
*/

test('the same arithmetic answers for every channel', function (string $channel) {
    $buyer = match ($channel) {
        'mandali' => Buyer::factory()->mandali($this->business)->create(),
        'vendor' => Buyer::factory()->vendor($this->business)->create(),
        'custom' => Buyer::factory()->inCustomChannel($this->business)->create(),
        default => directCustomer($this->business, [MilkType::Cow]),
    };

    $source = match ($channel) {
        'mandali' => SaleSource::MandaliDelivery,
        'vendor' => SaleSource::VendorSale,
        'custom' => SaleSource::GenericSale,
        default => null,
    };

    // 10.000 L at 70.00 = 700.00 owed.
    if ($source !== null) {
        ($this->sell)($buyer, $source, '10.000', '70.00');
    } else {
        app(SaveCustomerDailySale::class)
            ->handle($buyer, $this->date, Shift::Morning, MilkType::Cow, '10.000');
    }

    expectMoney($this->outstanding->outstandingFor($buyer), '700.00');

    // A 100.00 increase, a 50.00 decrease and a 200.00 receipt.
    $this->adjust->handle($buyer, BalanceAdjustmentDirection::Increase, '100.00', 'Agreed shortfall', $this->date);
    $this->adjust->handle($buyer, BalanceAdjustmentDirection::Decrease, '50.00', 'Spillage credit', $this->date);
    ($this->pay)($buyer, '200.00');

    $breakdown = $this->outstanding->breakdownFor($buyer);

    expectMoney($breakdown['sales'], '700.00');
    expectMoney($breakdown['adjustments'], '50.00');
    expectMoney($breakdown['payments'], '200.00');
    // 700 + 50 − 200
    expectMoney($breakdown['outstanding'], '550.00');
})->with(['mandali', 'vendor', 'custom', 'customer']);

test('there is no per-channel outstanding service', function () {
    foreach (['Mandali', 'Vendor', 'Customer', 'Generic'] as $channel) {
        expect(class_exists("App\\Services\\Buyers\\{$channel}OutstandingService"))->toBeFalse();
    }

    // And the one that exists is channel-agnostic: it names no channel at all.
    $source = file_get_contents(app_path('Services/Buyers/BuyerOutstandingService.php'));

    expect($source)->not->toContain('isMandali')
        ->not->toContain('isVendor')
        ->not->toContain('isDirectCustomer');
});

test('the bulk list figures agree with the per-buyer figures', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    $vendor = Buyer::factory()->vendor($this->business)->create();

    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '72.00');
    ($this->sell)($vendor, SaleSource::VendorSale, '5.000', '70.00');

    $this->adjust->handle($mandali, BalanceAdjustmentDirection::Increase, '80.00', 'Difference', $this->date);
    ($this->pay)($vendor, '100.00');

    $bulk = $this->outstanding->outstandingForMany(collect([$mandali, $vendor]));

    expectMoney($bulk[$mandali->id], $this->outstanding->outstandingFor($mandali));
    expectMoney($bulk[$vendor->id], $this->outstanding->outstandingFor($vendor));

    // 10 x 72 + 80
    expectMoney($bulk[$mandali->id], '800.00');
    // 5 x 70 − 100
    expectMoney($bulk[$vendor->id], '250.00');
});

test('the bulk query count does not grow with the buyer count', function () {
    $buyers = collect(range(1, 12))->map(function () {
        $buyer = Buyer::factory()->vendor($this->business)->create();
        ($this->sell)($buyer, SaleSource::VendorSale, '1.000', '70.00');
        $this->adjust->handle($buyer, BalanceAdjustmentDirection::Increase, '5.00', 'Rounding', $this->date);

        return $buyer;
    });

    // Warm the caches, then measure.
    $this->outstanding->outstandingForMany($buyers->take(1));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->outstanding->outstandingForMany($buyers);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Sales, payments and adjustments: three grouped queries, whatever the count.
    expect($queries)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| B. Adjustments move money owed, and nothing else
|--------------------------------------------------------------------------
*/

test('there is no screen for adjusting a balance by hand', function () {
    /*
     * An adjustment exists to account for a *reconciled difference* — a Mandali
     * statement that disagrees with the system figure — and that is the only way one
     * is created. A general "change this buyer's balance" form would be an
     * unauditable way to make any figure in the application say anything, which is
     * precisely what the receivable has to be defended against.
     *
     * Asserted structurally rather than left to the absence of a view, because adding
     * a route is the easy mistake and this is the test that would catch it.
     */
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'adjust'))
        ->unique()
        ->sort()
        ->values();

    // Phase 3 milk adjustments are a different thing: milk, not money.
    expect($routes->all())->toBe(['milk/adjustments', 'milk/adjustments/{adjustment}/cancel']);

    foreach (['buyers.adjustments.store', 'buyers.adjustments.create', 'buyers.balance.update'] as $name) {
        expect(app('router')->getRoutes()->getByName($name))->toBeNull();
    }
});

test('an adjustment changes no milk, no reconciliation and no account', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '72.00');

    $before = app(CalculateMilkReconciliation::class)
        ->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    $this->adjust->handle($mandali, BalanceAdjustmentDirection::Increase, '500.00', 'Statement difference', $this->date);

    $after = app(CalculateMilkReconciliation::class)
        ->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($after->sales->total)->toBe($before->sales->total)
        ->and($after->allocated)->toBe($before->allocated)
        ->and($after->remaining)->toBe($before->remaining)
        // It is not cash: no account was touched.
        ->and(FinancialLedgerEntry::query()->count())->toBe(0);

    // And the sale itself is untouched.
    expectMoney(MilkSale::query()->value('unit_rate'), '72.00');
});

test('the amount is always stored positive, with the direction carrying the sign', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '72.00');

    $decrease = $this->adjust->handle(
        $mandali, BalanceAdjustmentDirection::Decrease, '100.00', 'Credit agreed', $this->date
    );

    expect(bccomp(Quantity::money($decrease->amount), '0.00', 2))->toBeGreaterThan(0)
        ->and($decrease->direction)->toBe(BalanceAdjustmentDirection::Decrease);

    // The sign exists only in the calculation.
    expectMoney($decrease->signedAmount(), '-100.00');
    expectMoney($this->outstanding->breakdownFor($mandali)['adjustments'], '-100.00');
});

test('an adjustment needs a reason and a positive amount', function (string $amount, string $reason) {
    $mandali = Buyer::factory()->mandali($this->business)->create();

    expect(fn () => $this->adjust->handle(
        $mandali, BalanceAdjustmentDirection::Increase, $amount, $reason, $this->date
    ))->toThrow(ValidationException::class);

    expect(BuyerBalanceAdjustment::query()->count())->toBe(0);
})->with([
    'zero amount' => ['0.00', 'A reason'],
    'negative amount' => ['-50.00', 'A reason'],
    'blank reason' => ['50.00', ''],
    'whitespace reason' => ['50.00', '   '],
]);

test('a decrease cannot push a buyer into credit', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '72.00');

    expectMoney($this->outstanding->outstandingFor($mandali), '720.00');

    // Exactly the outstanding is allowed.
    $this->adjust->handle($mandali, BalanceAdjustmentDirection::Decrease, '720.00', 'Written off', $this->date);
    expectMoney($this->outstanding->outstandingFor($mandali), '0.00');

    /*
     * One paisa more is not. A credit balance is permitted by MASTER_SPEC section 27
     * only through an explicit authorised workflow, and no such workflow exists —
     * so allowing it would invent one by accident.
     */
    expect(fn () => $this->adjust->handle(
        $mandali, BalanceAdjustmentDirection::Decrease, '0.01', 'Over-credit', $this->date
    ))->toThrow(ValidationException::class);

    expectMoney($this->outstanding->outstandingFor($mandali), '0.00');
});

test('an increase is never refused', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();

    // Even with nothing owed: somebody owing more is always representable.
    $this->adjust->handle($mandali, BalanceAdjustmentDirection::Increase, '250.00', 'Prior period', $this->date);

    expectMoney($this->outstanding->outstandingFor($mandali), '250.00');
});

test('withdrawing an adjustment restores the balance with no second correction', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '72.00');

    $adjustment = $this->adjust->handle(
        $mandali, BalanceAdjustmentDirection::Increase, '500.00', 'Statement difference', $this->date
    );

    expectMoney($this->outstanding->outstandingFor($mandali), '1220.00');

    app(CancelBuyerBalanceAdjustment::class)->handle($adjustment, 'Entered against the wrong month');

    // Back where it was, and no compensating adjustment was written.
    expectMoney($this->outstanding->outstandingFor($mandali), '720.00');
    expect(BuyerBalanceAdjustment::query()->count())->toBe(1);

    // The history survives.
    $adjustment->refresh();

    expect($adjustment->isCancelled())->toBeTrue()
        ->and($adjustment->reason)->toBe('Statement difference')
        ->and($adjustment->cancellation_reason)->not->toBeEmpty();
    expectMoney($adjustment->amount, '500.00');
});

test('an adjustment cannot be withdrawn twice', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    $adjustment = $this->adjust->handle(
        $mandali, BalanceAdjustmentDirection::Increase, '100.00', 'Difference', $this->date
    );

    app(CancelBuyerBalanceAdjustment::class)->handle($adjustment, 'Wrong buyer');

    expect(fn () => app(CancelBuyerBalanceAdjustment::class)->handle($adjustment->refresh(), 'Again'))
        ->toThrow(ValidationException::class);
});

test('an adjustment for another buyer settlement is refused', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    $other = Buyer::factory()->mandali($this->business)->create();

    $settlement = BuyerSettlement::factory()->for($other)->finalized()->create();

    expect(fn () => $this->adjust->handle(
        $mandali, BalanceAdjustmentDirection::Increase, '100.00', 'Difference', $this->date, $settlement
    ))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| C. Payments: one table, one credit, every channel
|--------------------------------------------------------------------------
*/

test('there is no per-channel payment table', function () {
    foreach (['mandali_payments', 'vendor_payments', 'settlement_payments', 'customer_payments'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasTable('buyer_payments'))->toBeTrue();
});

test('a payment from any channel credits its account exactly once', function (string $channel) {
    $buyer = match ($channel) {
        'mandali' => Buyer::factory()->mandali($this->business)->create(),
        'vendor' => Buyer::factory()->vendor($this->business)->create(),
        default => Buyer::factory()->inCustomChannel($this->business)->create(),
    };

    $source = match ($channel) {
        'mandali' => SaleSource::MandaliDelivery,
        'vendor' => SaleSource::VendorSale,
        default => SaleSource::GenericSale,
    };

    ($this->sell)($buyer, $source, '10.000', '70.00');
    $payment = ($this->pay)($buyer, '300.00', $this->bank->id);

    $entries = FinancialLedgerEntry::query()
        ->where('reference_type', 'buyer_payment')
        ->where('reference_id', $payment->id)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->financial_account_id)->toBe($this->bank->id)
        ->and($entries->first()->direction->value)->toBe('credit');

    expectMoney($entries->first()->amount, '300.00');
    expectMoney($this->outstanding->outstandingFor($buyer), '400.00');
})->with(['mandali', 'vendor', 'custom']);

test('withdrawing a payment restores the balance and reverses the credit once', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '70.00');

    $payment = ($this->pay)($mandali, '300.00');
    expectMoney($this->outstanding->outstandingFor($mandali), '400.00');

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque bounced');

    expectMoney($this->outstanding->outstandingFor($mandali), '700.00');

    // One credit and exactly one reversing debit; the account nets to zero.
    $entries = FinancialLedgerEntry::query()
        ->where('reference_type', 'buyer_payment')
        ->where('reference_id', $payment->id)
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('direction')->map->value->sort()->values()->all())->toBe(['credit', 'debit']);

    // And a second cancellation is refused.
    expect(fn () => app(CancelBuyerPayment::class)->handle($payment->refresh(), 'Again'))
        ->toThrow(ValidationException::class);
});

test('a payment may not exceed the outstanding, on any channel', function () {
    $vendor = Buyer::factory()->vendor($this->business)->create();
    ($this->sell)($vendor, SaleSource::VendorSale, '10.000', '70.00');

    // Exactly the outstanding is fine.
    ($this->pay)($vendor, '700.00');
    expectMoney($this->outstanding->outstandingFor($vendor), '0.00');

    // A paisa more against a settled account is refused.
    expect(fn () => ($this->pay)($vendor, '0.01'))->toThrow(ValidationException::class);
});

test('an adjustment raises the ceiling a payment may reach', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '70.00');

    // 700 owed, so 800 would be refused...
    expect(fn () => ($this->pay)($mandali, '800.00'))->toThrow(ValidationException::class);

    // ...until an agreed increase makes it genuinely owed.
    $this->adjust->handle($mandali, BalanceAdjustmentDirection::Increase, '100.00', 'Statement difference', $this->date);

    ($this->pay)($mandali, '800.00');

    expectMoney($this->outstanding->outstandingFor($mandali), '0.00');
});

/*
|--------------------------------------------------------------------------
| D. Phase 4 is unchanged
|--------------------------------------------------------------------------
*/

test('a direct customer with no adjustments behaves exactly as before', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(SaveCustomerDailySale::class)
        ->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');

    $breakdown = $this->outstanding->breakdownFor($customer);

    // The adjustments term is real now, and it is zero because there are none —
    // which is exactly what Phase 4's screens displayed when it was hard-coded.
    expectMoney($breakdown['sales'], '210.00');
    expectMoney($breakdown['adjustments'], '0.00');
    expectMoney($breakdown['outstanding'], '210.00');
});

test('a cancelled adjustment is excluded like a cancelled sale or payment', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '10.000', '70.00');

    BuyerBalanceAdjustment::factory()->for($mandali)->increase('999.00')->cancelled()->create();

    // Cancelled rows never reach a balance, whatever table they are in.
    expectMoney($this->outstanding->breakdownFor($mandali)['adjustments'], '0.00');
    expectMoney($this->outstanding->outstandingFor($mandali), '700.00');
});
