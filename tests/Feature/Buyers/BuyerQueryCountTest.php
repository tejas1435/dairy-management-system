<?php

use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use Illuminate\Support\Facades\DB;

/*
 * Query-count guards for the Phase 5 screens.
 *
 * Exact equality between a page showing one related record and the same page showing
 * many, with the caches warmed first — the shape the Phase 2, 3 and 4 guards settled
 * on. A list of buyers each with its own outstanding balance is the obvious place for
 * a query per row to appear, and the obvious place for nobody to notice until a farm
 * has two hundred vendors.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '500000.00');
    $this->cash = $accounts['cash'];

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '5000.000', buffalo: '5000.000');

    $this->actingAs(superAdmin());

    $this->deliver = fn (Buyer $buyer, string $quantity = '10.000', ?string $date = null) => app(RecordChannelSale::class)->handle(
        buyer: $buyer,
        source: SaleSource::MandaliDelivery,
        date: $date ?? $this->date,
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: $quantity,
        rate: '72.00',
        attributes: ['fat_percentage' => '4.50'],
    );
});

/** Counts the queries a callback runs. */
function countBuyerQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the Mandali list costs the same for one Mandali as for twenty', function () {
    $first = Buyer::factory()->mandali($this->business)->create();
    ($this->deliver)($first);

    // Warm the per-request caches, then measure the one-row page.
    $this->get(route('mandalis.index'))->assertOk();
    $one = countBuyerQueries(fn () => $this->get(route('mandalis.index'))->assertOk());

    foreach (range(1, 19) as $index) {
        $buyer = Buyer::factory()->mandali($this->business)->create();
        ($this->deliver)($buyer, '5.000');
        app(RecordBuyerPayment::class)->handle($buyer, [
            'amount' => '100.00',
            'payment_date' => $this->date,
            'financial_account_id' => $this->cash->id,
        ]);
    }

    $many = countBuyerQueries(fn () => $this->get(route('mandalis.index'))->assertOk());

    expect($many)->toBe($one);
});

test('the other-buyers list costs the same for one buyer as for twenty', function () {
    $first = Buyer::factory()->inCustomChannel($this->business)->create();

    $this->get(route('other-buyers.index'))->assertOk();
    $one = countBuyerQueries(fn () => $this->get(route('other-buyers.index'))->assertOk());

    Buyer::factory()->count(19)->inCustomChannel($this->business)->create();

    $many = countBuyerQueries(fn () => $this->get(route('other-buyers.index'))->assertOk());

    expect($many)->toBe($one)
        ->and($first->exists)->toBeTrue();
});

test('the trade profile costs the same for one transaction as for forty', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->deliver)($mandali);

    /*
     * One receipt in the baseline as well as one delivery. With no receipt at all,
     * Eloquent skips the three eager loads the payment rows need — there are no parent
     * models to load them for — and the comparison would measure that rather than a
     * query per row.
     */
    app(RecordBuyerPayment::class)->handle($mandali, [
        'amount' => '10.00',
        'payment_date' => $this->date,
        'financial_account_id' => $this->cash->id,
    ]);

    $url = route('mandalis.show', $mandali);

    $this->get($url)->assertOk();
    $one = countBuyerQueries(fn () => $this->get($url)->assertOk());

    foreach (range(1, 19) as $index) {
        ($this->deliver)($mandali, '1.000');
        app(RecordBuyerPayment::class)->handle($mandali, [
            'amount' => '10.00',
            'payment_date' => $this->date,
            'financial_account_id' => $this->cash->id,
        ]);
    }

    $many = countBuyerQueries(fn () => $this->get($url)->assertOk());

    expect($many)->toBe($one);
});

test('the period statement costs the same for one transaction as for forty', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->deliver)($mandali);

    // One receipt in the baseline too, for the reason given on the profile above.
    app(RecordBuyerPayment::class)->handle($mandali, [
        'amount' => '10.00',
        'payment_date' => $this->date,
        'financial_account_id' => $this->cash->id,
    ]);

    $url = route('mandalis.statement', [$mandali, 'month' => '2026-10']);

    $this->get($url)->assertOk();
    $one = countBuyerQueries(fn () => $this->get($url)->assertOk());

    foreach (range(1, 19) as $index) {
        ($this->deliver)($mandali, '1.000');
        app(RecordBuyerPayment::class)->handle($mandali, [
            'amount' => '10.00',
            'payment_date' => $this->date,
            'financial_account_id' => $this->cash->id,
        ]);
    }

    $many = countBuyerQueries(fn () => $this->get($url)->assertOk());

    expect($many)->toBe($one);
});

test('the trade profile costs the same for one settlement as for twelve', function () {
    /*
     * The profile shows every settlement with what it still owes, and offers a receipt
     * form listing the ones a payment may be attached to. Both are derived from active
     * receipts, which is how the page came to issue a query per settlement — invisible
     * at one and growing every month the Mandali settles.
     */
    $mandali = Buyer::factory()->mandali($this->business)->create();

    /*
     * The baseline settles the **current** month, and the eleven before it are added
     * afterwards. The profile's ledger defaults to this month, and with nothing in it
     * Eloquent would skip the payment eager loads entirely — so a baseline in January
     * would measure that rather than the per-settlement cost.
     */
    $settle = function (string $month) use ($mandali): void {
        $start = $month.'-01';
        $end = date('Y-m-t', strtotime($start));

        seedProductionFor($this->farm, $start, cow: '500.000', buffalo: '500.000');
        ($this->deliver)($mandali, '10.000', $start);

        $settlement = app(CreateBuyerSettlement::class)->handle($mandali, $start, $end);
        app(FinalizeBuyerSettlement::class)->handle($settlement);

        app(RecordBuyerPayment::class)->handle($mandali, [
            'amount' => '100.00',
            'payment_date' => $start,
            'financial_account_id' => $this->cash->id,
            'buyer_settlement_id' => $settlement->getKey(),
        ]);
    };

    $settle(now()->format('Y-m'));

    $url = route('mandalis.show', $mandali);

    $this->get($url)->assertOk();
    $one = countBuyerQueries(fn () => $this->get($url)->assertOk());

    foreach (range(1, 11) as $monthsBack) {
        $settle(now()->copy()->subMonthsNoOverflow($monthsBack)->format('Y-m'));
    }

    $many = countBuyerQueries(fn () => $this->get($url)->assertOk());

    expect($many)->toBe($one);
});

test('the settlement list costs the same for one settlement as for twelve', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();

    /*
     * Each settlement's paid total and payment status are derived from its receipts,
     * so a list of a year's settlements is exactly where a derived figure turns into
     * a query per row.
     */
    $settle = function (string $month) use ($mandali): void {
        $start = '2026-'.$month.'-01';
        $end = date('Y-m-t', strtotime($start));

        seedProductionFor($this->farm, $start, cow: '500.000', buffalo: '500.000');
        ($this->deliver)($mandali, '10.000', $start);

        $settlement = app(CreateBuyerSettlement::class)->handle($mandali, $start, $end);
        app(FinalizeBuyerSettlement::class)->handle($settlement);

        app(RecordBuyerPayment::class)->handle($mandali, [
            'amount' => '100.00',
            'payment_date' => $end,
            'financial_account_id' => $this->cash->id,
            'buyer_settlement_id' => $settlement->getKey(),
        ]);
    };

    $settle('01');

    $url = route('mandalis.settlements.index', $mandali);

    $this->get($url)->assertOk();
    $one = countBuyerQueries(fn () => $this->get($url)->assertOk());

    foreach (['02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'] as $month) {
        $settle($month);
    }

    $many = countBuyerQueries(fn () => $this->get($url)->assertOk());

    expect($many)->toBe($one);
});

test('the channel sale list costs the same for one sale as for thirty', function () {
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->deliver)($mandali);

    $url = route('milk.mandali-deliveries.index', ['from' => '2026-10-01', 'to' => '2026-10-31']);

    $this->get($url)->assertOk();
    $one = countBuyerQueries(fn () => $this->get($url)->assertOk());

    foreach (range(1, 29) as $index) {
        ($this->deliver)($mandali, '1.000');
    }

    $many = countBuyerQueries(fn () => $this->get($url)->assertOk());

    expect($many)->toBe($one);
});
