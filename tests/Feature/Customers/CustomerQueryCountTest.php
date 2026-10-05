<?php

use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Customers\CustomerEligibilityService;
use App\Services\Customers\CustomerLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Query-count guards for the Phase 4 screens.
 *
 * Asserted as exact equality between a page showing one related record and the same
 * page showing many, with the per-request caches warmed first — the same shape the
 * Phase 2 and Phase 3 guards settled on, for the same reason: comparing against an
 * empty page measures Eloquent skipping eager loads rather than a query per row.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '100000.00');
    $this->cash = $accounts['cash'];

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00');

    $this->customer = directCustomer($this->business, [MilkType::Cow]);

    $this->actingAs($this->admin);
});

/** Counts the queries a callback runs. */
function countCustomerQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** One unmeasured request, so per-request caches are loaded. */
function warmCustomerCaches(string $url): void
{
    test()->get($url)->assertOk();
}

/** Deliveries across several dates, so the ledger has rows to pivot. */
function deliverOver(object $test, int $days): void
{
    $sell = app(SaveCustomerDailySale::class);

    foreach (range(1, $days) as $offset) {
        $date = Carbon::parse('2026-10-01')->addDays($offset)->toDateString();

        seedProductionFor($test->farm, $date, cow: '1000.000', buffalo: '1000.000');

        $sell->handle($test->customer, $date, Shift::Morning, MilkType::Cow, '1.000');
        $sell->handle($test->customer, $date, Shift::Evening, MilkType::Cow, '1.000');
    }
}

test('the customer list costs the same however many customers it shows', function () {
    directCustomer($this->business, [MilkType::Cow]);

    $url = route('customers.index');
    warmCustomerCaches($url);

    $withTwo = countCustomerQueries(fn () => $this->get($url)->assertOk());

    collect(range(1, 20))->each(fn () => directCustomer($this->business, [MilkType::Cow]));

    $withTwentyTwo = countCustomerQueries(fn () => $this->get($url)->assertOk());

    // The outstanding figures and the paused map are two grouped queries for the
    // whole page rather than a pair per row.
    expect($withTwentyTwo)->toBe($withTwo);
});

test('the customer profile costs the same however long the ledger is', function () {
    deliverOver($this, 1);

    app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => '2026-10-02', 'amount' => '10.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $url = route('customers.show', [$this->customer, 'from' => '2026-10-01', 'to' => '2026-10-31']);
    warmCustomerCaches($url);

    $short = countCustomerQueries(fn () => $this->get($url)->assertOk());

    deliverOver($this, 20);

    $long = countCustomerQueries(fn () => $this->get($url)->assertOk());

    expect($long)->toBe($short);
});

test('the ledger statement does not grow with the number of rows', function () {
    $ledger = app(CustomerLedgerService::class);

    deliverOver($this, 2);
    $sparse = countCustomerQueries(fn () => $ledger->statement($this->customer, '2026-10-01', '2026-10-31'));

    deliverOver($this, 20);
    $busy = countCustomerQueries(fn () => $ledger->statement($this->customer, '2026-10-01', '2026-10-31'));

    expect($busy)->toBe($sparse);
});

test('the bulk outstanding query count does not grow with the number of customers', function () {
    /*
     * Three grouped queries — sales, payments and receivable adjustments — and three
     * for forty customers as well as for two. It was two until Phase 5 filled in the
     * adjustments term; what this guards is that the count is fixed, not what the
     * fixed number happens to be.
     */
    $outstanding = app(BuyerOutstandingService::class);

    $few = collect(range(1, 2))->map(fn () => directCustomer($this->business, [MilkType::Cow]))
        ->pluck('id')->all();
    $many = collect(range(1, 40))->map(fn () => directCustomer($this->business, [MilkType::Cow]))
        ->pluck('id')->all();

    expect(countCustomerQueries(fn () => $outstanding->outstandingForMany($few)))->toBe(3)
        ->and(countCustomerQueries(fn () => $outstanding->outstandingForMany($many)))->toBe(3);
});

test('the batch eligibility query does not grow with the number of customers', function () {
    $eligibility = app(CustomerEligibilityService::class);

    $sparse = countCustomerQueries(fn () => $eligibility->forDate($this->date));

    collect(range(1, 30))->each(fn () => directCustomer($this->business, [MilkType::Cow]));

    $busy = countCustomerQueries(fn () => $eligibility->forDate($this->date));

    // Customers, their preferences, and one paused lookup — three, whatever the count.
    // This is the query the Pass 2 grid will run once per page.
    expect($busy)->toBe($sparse);
});
