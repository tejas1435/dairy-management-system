<?php

use App\Actions\Buyers\CreateBuyerBalanceAdjustment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\BalanceAdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;

/*
 * The on-screen period statement (MASTER_SPEC section 23).
 *
 * Every figure on it is asserted against a hand calculation, not against whatever the
 * services happen to return — the point of the screen is that a Mandali's own
 * statement can be reconciled against it line by line, and a test that reads its
 * expectations out of the same service it is testing would prove nothing.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();
    $this->cash = seedAccounts($this->business, cashOpening: '100000.00')['cash'];

    $this->farm = $this->business->primaryFarm();

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);
    $this->vendor = Buyer::factory()->vendor($this->business)->create(['name' => 'Patel Dairy']);

    BuyerPriceRule::factory()->for($this->vendor)->forType(MilkType::Cow)
        ->rate('74.00')->period('2026-09-01')->create();

    $this->actingAs(superAdmin());
});

/**
 * Three October collections for the Mandali, at three different agreed rates.
 *
 * 40.000 × 72.00 = 2,880.00
 * 35.500 × 71.50 = 2,538.25
 * 30.250 × 73.25 = 2,215.81  (30.25 × 73.25 = 2,215.8125, half-up)
 * ------------------------------
 *                   7,634.06
 */
function octoberCollections(): string
{
    $record = app(RecordChannelSale::class);

    foreach ([
        ['2026-10-05', Shift::Morning, '40.000', '72.00'],
        ['2026-10-12', Shift::Evening, '35.500', '71.50'],
        ['2026-10-20', Shift::Morning, '30.250', '73.25'],
    ] as [$date, $shift, $quantity, $rate]) {
        seedProductionFor(test()->farm, $date, cow: '500.000', buffalo: '500.000');

        $record->handle(
            buyer: test()->mandali,
            source: SaleSource::MandaliDelivery,
            date: $date,
            shift: $shift,
            milkType: MilkType::Cow,
            quantity: $quantity,
            rate: $rate,
            attributes: ['fat_percentage' => '4.20', 'snf_percentage' => '8.60'],
        );
    }

    return '7634.06';
}

/*
|--------------------------------------------------------------------------
| A. The five summary figures, hand calculated
|--------------------------------------------------------------------------
*/

test('the statement summary reports the period figures the specification lists', function () {
    octoberCollections();

    // A receivable correction of +250.00 and a receipt of 5,000.00 inside the period.
    app(CreateBuyerBalanceAdjustment::class)->handle(
        $this->mandali, BalanceAdjustmentDirection::Increase, '250.00',
        'Short weight recovered on the 12th', '2026-10-15',
    );

    app(RecordBuyerPayment::class)->handle($this->mandali, [
        'amount' => '5000.00',
        'payment_date' => '2026-10-25',
        'financial_account_id' => $this->cash->id,
    ]);

    $response = $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))->assertOk();

    $response
        ->assertSee('Shree Dairy Mandali')
        ->assertSee(__('buyers.statement.summary'))
        // 40.000 + 35.500 + 30.250 litres.
        ->assertSee('105.750')
        // Expected sales: 2,880.00 + 2,538.25 + 2,215.81.
        ->assertSee('7,634.06')
        ->assertSee('250.00')
        ->assertSee('5,000.00')
        // Outstanding: 7,634.06 + 250.00 − 5,000.00.
        ->assertSee('2,884.06');
});

test('the summary shows the whole balance, not only the period movement', function () {
    octoberCollections();

    // September is fully paid, so the month's own movement is zero.
    seedProductionFor($this->farm, '2026-09-10', cow: '500.000', buffalo: '500.000');

    app(RecordChannelSale::class)->handle(
        buyer: $this->mandali, source: SaleSource::MandaliDelivery, date: '2026-09-10',
        shift: Shift::Morning, milkType: MilkType::Cow, quantity: '10.000', rate: '70.00',
        attributes: ['fat_percentage' => '4.00'],
    );

    app(RecordBuyerPayment::class)->handle($this->mandali, [
        'amount' => '700.00',
        'payment_date' => '2026-09-30',
        'financial_account_id' => $this->cash->id,
    ]);

    // Looking at September alone: 700.00 collected, 700.00 received — but October's
    // 7,634.06 is still owed, and the statement has to say so.
    $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-09']))
        ->assertOk()
        ->assertSee('700.00')
        ->assertSee('7,634.06');
});

test('the opening balance carries the history above the period', function () {
    octoberCollections();

    $html = $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-11']))
        ->assertOk()
        ->getContent();

    // Nothing happened in November, so the statement is the opening balance alone.
    expect($html)->toContain(__('buyers.ledger.opening'))
        ->toContain('7,634.06');
});

/*
|--------------------------------------------------------------------------
| B. Transaction detail
|--------------------------------------------------------------------------
*/

test('each collection appears with its quality figures and its own rate snapshot', function () {
    octoberCollections();

    $html = $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(__('buyers.statement.transactions'))
        // Quantities, each with its own rate and amount: three rates, not one average.
        ->toContain('40.000')->toContain('72.00')->toContain('2,880.00')
        ->toContain('35.500')->toContain('71.50')->toContain('2,538.25')
        ->toContain('30.250')->toContain('73.25')->toContain('2,215.81')
        // Fat and SNF, as reference figures beside the quantity.
        ->toContain('4.20')->toContain('8.60')
        // Shift, so a two-collection day is unambiguous.
        ->toContain(Shift::Morning->label())
        ->toContain(Shift::Evening->label());
});

test('adjustments and receipts are shown as themselves, not folded into the sales', function () {
    octoberCollections();

    app(CreateBuyerBalanceAdjustment::class)->handle(
        $this->mandali, BalanceAdjustmentDirection::Decrease, '134.06',
        'Rounding agreed with the Mandali', '2026-10-28',
    );

    app(RecordBuyerPayment::class)->handle($this->mandali, [
        'amount' => '7500.00',
        'payment_date' => '2026-10-31',
        'financial_account_id' => $this->cash->id,
    ]);

    $html = $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(__('buyers.adjustments.title'))
        ->toContain('Rounding agreed with the Mandali')
        ->toContain(__('buyers.payment.title'))
        // Closing balance: 7,634.06 − 134.06 − 7,500.00 = 0.00, reached by the running
        // balance rather than stated anywhere.
        ->toContain(__('buyers.ledger.closing'));

    // And the sales total is untouched by either of them.
    expect($html)->toContain('7,634.06');
});

test('a withdrawn sale leaves the statement', function () {
    octoberCollections();

    $sale = $this->mandali->milkSales()->orderBy('sale_date')->firstOrFail();

    app(CancelMilkSale::class)->handle($sale, 'Recorded against the wrong Mandali');

    $html = $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))
        ->assertOk()
        ->getContent();

    // 7,634.06 − 2,880.00.
    expect($html)->toContain('4,754.06')
        ->not->toContain('2,880.00');
});

/*
|--------------------------------------------------------------------------
| C. Period selection
|--------------------------------------------------------------------------
*/

test('the statement defaults to the current month', function () {
    $this->travelTo('2026-10-15');

    $html = $this->get(route('mandalis.statement', $this->mandali))->assertOk()->getContent();

    expect($html)->toContain('2026-10-01')
        ->toContain('2026-10-31')
        // The arrows reach the months on either side.
        ->toContain('month=2026-09')
        ->toContain('month=2026-11');
});

test('an explicit period overrides the month and relabels the page', function () {
    octoberCollections();

    $html = $this->get(route('mandalis.statement', [
        $this->mandali, 'from' => '2026-10-10', 'to' => '2026-10-15',
    ]))->assertOk()->getContent();

    expect($html)
        // Only the 12th falls inside it, so that is the one amount charged.
        ->toContain('2,538.25')
        ->not->toContain('2,215.81')
        // The 5th is above the period, so it is in the opening balance rather than a
        // row: 2,880.00 brought forward, closing at 2,880.00 + 2,538.25.
        ->toContain('2,880.00')
        ->toContain('5,418.25')
        // And the heading follows the dates shown rather than today.
        ->toContain('October 2026');
});

test('a malformed month shows the current month rather than failing', function (string $month) {
    $this->travelTo('2026-10-15');

    $this->get(route('mandalis.statement', [$this->mandali, 'month' => $month]))
        ->assertOk()
        ->assertSee('2026-10-01');
})->with([
    'nonsense' => ['last-tuesday'],
    'wrong shape' => ['2026/10'],
    'month 13' => ['2026-13'],
    'empty' => [''],
]);

/*
|--------------------------------------------------------------------------
| D. Settlements are shown, not merged
|--------------------------------------------------------------------------
*/

test('a settlement covering the period is listed beside the period figures', function () {
    octoberCollections();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-10-01', '2026-10-31', '7600.00');

    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $html = $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(__('buyers.statement.settlements_in_period'))
        ->toContain(__('buyers.settlement_statuses.finalized'))
        // The agreed figure and the system figure, both visible, and the difference
        // that was posted between them: 7,600.00 − 7,634.06.
        ->toContain('7,600.00')
        ->toContain('7,634.06')
        ->toContain('34.06');
});

test('a settlement for another period is not listed', function () {
    octoberCollections();

    app(CreateBuyerSettlement::class)->handle($this->mandali, '2026-08-01', '2026-08-31', '100.00');

    $this->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))
        ->assertOk()
        ->assertDontSee(__('buyers.statement.settlements_in_period'));
});

test('a vendor statement has no settlement section at all', function () {
    seedProductionFor($this->farm, '2026-10-06', cow: '500.000', buffalo: '500.000');

    app(RecordChannelSale::class)->handle(
        buyer: $this->vendor, source: SaleSource::VendorSale, date: '2026-10-06',
        shift: Shift::Morning, milkType: MilkType::Cow, quantity: '20.000', rate: null,
    );

    $html = $this->get(route('vendors.statement', [$this->vendor, 'month' => '2026-10']))
        ->assertOk()
        ->getContent();

    // 20.000 × 74.00, from the vendor's own configured rate.
    expect($html)->toContain('1,480.00')
        ->not->toContain(__('buyers.statement.settlements_in_period'));
});

/*
|--------------------------------------------------------------------------
| E. Authorisation, channels and locales
|--------------------------------------------------------------------------
*/

test('the statement needs the buyer channel view permission', function () {
    $this->actingAs(userWithoutPermissions());
    $this->get(route('mandalis.statement', $this->mandali))->assertForbidden();

    $this->actingAs(userWithPermissions(['mandali.view']));
    $this->get(route('mandalis.statement', $this->mandali))->assertOk();

    // The vendor's statement is a different family, not a wider version of this one.
    $this->get(route('vendors.statement', $this->vendor))->assertForbidden();
});

test('a statement cannot be opened through the wrong channel route', function () {
    $this->get(route('mandalis.statement', $this->vendor))->assertNotFound();
    $this->get(route('vendors.statement', $this->mandali))->assertNotFound();
});

test('the profile links to the statement for the period it is showing', function () {
    $html = $this->get(route('mandalis.show', [
        $this->mandali, 'from' => '2026-10-01', 'to' => '2026-10-31',
    ]))->assertOk()->getContent();

    // Escaped, because the query string is rendered into an attribute.
    expect($html)->toContain(e(route('mandalis.statement', [
        $this->mandali, 'from' => '2026-10-01', 'to' => '2026-10-31',
    ])));
});

test('the statement renders in Gujarati and Hindi with no raw keys', function (string $locale) {
    octoberCollections();

    $settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-10-01', '2026-10-31', '7600.00');
    app(FinalizeBuyerSettlement::class)->handle($settlement);

    $html = $this->actingAs(superAdmin(['locale' => $locale]))
        ->get(route('mandalis.statement', [$this->mandali, 'month' => '2026-10']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toMatch('/\b(buyers|milk|nav|app|customers)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');

    expect(__('buyers.statement.title', [], $locale))->not->toBe(__('buyers.statement.title', [], 'en'))
        ->and(__('buyers.ledger.opening', [], $locale))->not->toBe(__('buyers.ledger.opening', [], 'en'));
})->with(['gu', 'hi']);
