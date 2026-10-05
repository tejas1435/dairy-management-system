<?php

use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Services\Milk\CustomerDailyEntryGrid;
use App\Services\PriceResolver;
use Illuminate\Support\Facades\DB;

/*
 * The grid loads every customer at once, by design: it is not a paginated picker,
 * and the operator must not have to page through the round (MASTER_SPEC section 19).
 *
 * That makes an N+1 here worse than anywhere else in the application — the cost
 * would grow with the customer list rather than with anything the operator did. So
 * the cost is asserted as **exact equality** between a grid of one row and a grid of
 * many, with caches warmed first, which is the shape the Phase 2 and Phase 3 guards
 * settled on.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '1000.000', buffalo: '1000.000');

    $this->url = route('milk.customer-entry.index', ['date' => $this->date]);

    $this->actingAs(superAdmin());
});

/** Counts the queries a callback runs. */
function countGridQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Customers with a delivery already recorded, so the sale lookup is exercised too. */
function seedGridCustomers(int $count, bool $withSales = true): void
{
    $save = app(SaveCustomerDailySale::class);

    foreach (range(1, $count) as $i) {
        $customer = directCustomer(test()->business, [MilkType::Cow, MilkType::Buffalo], [
            'name' => "Customer {$i}",
            'area' => $i % 2 === 0 ? 'North' : 'South',
        ]);

        if ($withSales) {
            $save->handle($customer, test()->date, Shift::Morning, MilkType::Cow, '1.000');
            $save->handle($customer, test()->date, Shift::Evening, MilkType::Buffalo, '2.000');
        }
    }
}

test('the grid costs the same for one customer as for thirty', function () {
    seedGridCustomers(1);

    // One unmeasured request so permission and config caches are loaded.
    $this->get($this->url)->assertOk();

    $one = countGridQueries(fn () => $this->get($this->url)->assertOk());

    seedGridCustomers(29);

    $many = countGridQueries(fn () => $this->get($this->url)->assertOk());

    expect($many)->toBe($one, "Grid issued {$many} queries for 30 customers against {$one} for 1.");
});

test('the row builder itself is a fixed number of queries', function () {
    seedGridCustomers(1);

    $grid = app(CustomerDailyEntryGrid::class);

    $resolver = app(PriceResolver::class);

    $resolver->forget();
    $one = countGridQueries(fn () => $grid->rowsFor($this->date, $this->farm->id));

    seedGridCustomers(49);

    $resolver->forget();
    $many = countGridQueries(fn () => $grid->rowsFor($this->date, $this->farm->id));

    /*
     * Seven, and seven whatever the customer count: the customers themselves, their
     * sales channels and preferences eager-loaded, the pauses covering the date, the
     * day's grid sales, and two priming queries for prices. Fifty customers taking
     * two milk types each is a hundred rows built from the same seven reads.
     */
    expect($many)->toBe($one)
        ->and($one)->toBe(7);
});

test('price resolution is primed in two queries however many customers there are', function () {
    seedGridCustomers(25, withSales: false);

    $resolver = app(PriceResolver::class);
    $resolver->forget();

    $customers = Buyer::query()->directCustomers()->get();

    $queries = countGridQueries(fn () => $resolver->primeFor($customers, $this->date));

    expect($queries)->toBe(2);

    // And nothing further is needed to answer for any of them.
    $afterPriming = countGridQueries(function () use ($resolver, $customers): void {
        foreach ($customers as $customer) {
            foreach (MilkType::cases() as $milkType) {
                $resolver->resolve($customer, $milkType, $this->date);
            }
        }
    });

    expect($afterPriming)->toBe(0);
});

test('priming agrees with resolving one at a time, overrides included', function () {
    $plain = directCustomer($this->business, [MilkType::Cow]);
    $overridden = directCustomer($this->business, [MilkType::Cow]);

    BuyerPriceRule::factory()->for($overridden)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-10-01')->create();

    $primed = app(PriceResolver::class);
    $primed->forget();
    $primed->primeFor([$plain, $overridden], $this->date);

    $single = app(PriceResolver::class);
    $single->forget();

    foreach ([$plain, $overridden] as $customer) {
        $a = $primed->resolve($customer, MilkType::Cow, $this->date);
        $b = $single->resolve($customer, MilkType::Cow, $this->date);

        expect($a->found)->toBe($b->found)
            ->and($a->rate())->toBe($b->rate())
            ->and($a->source)->toBe($b->source);

        $single->forget();
    }
});

test('priming does not invent a price where there is none', function () {
    MilkPriceRule::query()->delete();

    $customer = directCustomer($this->business, [MilkType::Cow]);

    $resolver = app(PriceResolver::class);
    $resolver->forget();
    $resolver->primeFor([$customer], $this->date);

    $price = $resolver->resolve($customer, MilkType::Cow, $this->date);

    expect($price->found)->toBeFalse()
        ->and($price->rate)->toBeNull();
});

test('saving a day costs queries in proportion to what changed, not to the grid size', function () {
    seedGridCustomers(20);

    $target = Buyer::query()->directCustomers()->orderBy('id')->first();

    $unchangedRows = Buyer::query()->directCustomers()->get()
        ->map(fn ($customer): array => [
            'buyer_id' => $customer->id,
            'milk_type' => 'cow',
            'morning' => '1.000',
            'evening' => null,
        ])->all();

    $this->postJson(route('milk.customer-entry.store'), [
        'date' => $this->date, 'rows' => $unchangedRows,
    ])->assertOk();

    // A whole day re-submitted with nothing changed...
    $noChange = countGridQueries(fn () => $this->postJson(route('milk.customer-entry.store'), [
        'date' => $this->date, 'rows' => $unchangedRows,
    ])->assertOk()->assertJsonPath('summary.changed', 0));

    // ...versus the same day with exactly one cell edited.
    $oneChanged = collect($unchangedRows)->map(fn (array $row): array => $row['buyer_id'] === $target->id
        ? ['...' => null] + array_merge($row, ['morning' => '1.500'])
        : $row)->all();

    $withChange = countGridQueries(fn () => $this->postJson(route('milk.customer-entry.store'), [
        'date' => $this->date, 'rows' => $oneChanged,
    ])->assertOk()->assertJsonPath('summary.changed', 1));

    /*
     * Twenty untouched rows must not cost twenty writes. The difference between the
     * two saves is the work of the single changed cell plus its audit record, not a
     * per-row tax.
     */
    expect($withChange - $noChange)->toBeLessThanOrEqual(12);
});

test('filtering is visual only and never alters what a save submits', function () {
    seedGridCustomers(4);

    $source = file_get_contents(resource_path('js/customer-daily-entry.js'));

    /*
     * The payload is built from every editable row, not from the visible ones, and
     * filtering only toggles `hidden`. A hidden row is not a cleared row — if the
     * payload were built from what is on screen, filtering the table and pressing
     * Save Day would cancel every delivery the operator had filtered out.
     */
    $payloadSection = substr($source, (int) strpos($source, 'payload()'));
    $payloadSection = substr($payloadSection, 0, (int) strpos($payloadSection, 'async save()'));

    expect($payloadSection)->toContain("row.dataset.editable === '1'")
        ->not->toContain('hidden')
        ->not->toContain(':not([hidden])')
        ->not->toContain('offsetParent');

    $filterSection = substr($source, (int) strpos($source, 'applyFilters()'));
    $filterSection = substr($filterSection, 0, (int) strpos($filterSection, 'payload()'));

    // The filter touches visibility and nothing else — no value is written.
    expect($filterSection)->toContain('row.hidden = !matches')
        ->not->toContain('.value =');
});
