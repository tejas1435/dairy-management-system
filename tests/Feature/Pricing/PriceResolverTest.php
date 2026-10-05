<?php

use App\Actions\Milk\CreateMilkSale;
use App\Enums\MilkType;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Models\SalesChannel;
use App\Services\PriceResolver;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedPhase2Masters();

    $this->channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $this->buyer = Buyer::factory()->inChannel($this->channel)->create(['name' => 'Rajesh Patel']);
    $this->other = Buyer::factory()->inChannel($this->channel)->create(['name' => 'Amit Patel']);

    $this->resolver = app(PriceResolver::class);
});

/** Business default cow at 70 from September, open ended. */
function defaultCow(string $rate = '70.00', string $from = '2026-09-01', ?string $to = null): MilkPriceRule
{
    return MilkPriceRule::factory()
        ->for(test()->business)
        ->forType(MilkType::Cow)
        ->rate($rate)
        ->period($from, $to)
        ->create();
}

/*
|--------------------------------------------------------------------------
| Resolution order
|--------------------------------------------------------------------------
*/

test('a buyer override wins over the business default', function () {
    defaultCow('70.00');
    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-09-01')->create();

    $price = $this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15');

    expect($price->found)->toBeTrue()
        ->and($price->isFromBuyerOverride())->toBeTrue();
    expectMoney($price->rate(), '72.00');
});

test('a buyer with no override falls back to the business default', function () {
    defaultCow('70.00');
    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-09-01')->create();

    $price = $this->resolver->resolve($this->other, MilkType::Cow, '2026-09-15');

    expect($price->isFromBusinessDefault())->toBeTrue();
    expectMoney($price->rate(), '70.00');
});

/*
|--------------------------------------------------------------------------
| Dates
|--------------------------------------------------------------------------
*/

test('resolution follows the sale date, not today', function () {
    defaultCow('70.00', '2026-09-01', '2026-09-30');
    defaultCow('75.00', '2026-10-01');

    // A sale dated in September stays at September's rate however long ago
    // that was.
    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '70.00');
    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-10-15')->rate(), '75.00');
});

test('period boundaries are inclusive at both ends', function (string $date, string $expected) {
    defaultCow('70.00', '2026-09-01', '2026-09-30');
    defaultCow('75.00', '2026-10-01');

    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, $date)->rate(), $expected);
})->with([
    'first day of the first period' => ['2026-09-01', '70.00'],
    'last day of the first period' => ['2026-09-30', '70.00'],
    'first day of the second period' => ['2026-10-01', '75.00'],
]);

test('a price is not used before it takes effect', function () {
    defaultCow('70.00', '2026-09-01', '2026-09-30');
    defaultCow('75.00', '2026-10-01');

    // The day before the new rate starts must still be the old rate.
    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-30')->rate(), '70.00');

    // And a date before any rule exists resolves to nothing at all.
    expect($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-08-31')->found)->toBeFalse();
});

test('an expired buyer override falls back to the business default', function () {
    defaultCow('70.00', '2026-01-01');
    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-09-01', '2026-09-30')->create();

    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '72.00');

    $after = $this->resolver->resolve($this->buyer, MilkType::Cow, '2026-10-01');
    expect($after->isFromBusinessDefault())->toBeTrue();
    expectMoney($after->rate(), '70.00');
});

test('a future buyer override is not applied early', function () {
    defaultCow('70.00', '2026-01-01');
    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('80.00')->period('2026-12-01')->create();

    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '70.00');
    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-12-15')->rate(), '80.00');
});

/*
|--------------------------------------------------------------------------
| Missing price: the case that must never be papered over
|--------------------------------------------------------------------------
*/

test('a missing price is an explicit failure, never zero', function () {
    $price = $this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15');

    expect($price->found)->toBeFalse()
        ->and($price->rate)->toBeNull()
        // The three ways this could silently go wrong.
        ->and($price->rate)->not->toBe('0')
        ->and($price->rate)->not->toBe('0.00')
        ->and($price->rate)->not->toBe(0);
});

test('reading the rate of a failed resolution throws rather than returning a number', function () {
    $price = $this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15');

    expect(fn () => $price->rate())->toThrow(RuntimeException::class);
});

test('a failed resolution carries a message a sale screen can show', function () {
    $price = $this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15');

    expect($price->reason())->not->toBeEmpty()
        ->and($price->reason())->toContain('2026-09-15');
});

test('a buyer override for one milk type does not rescue a missing default for the other', function () {
    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-09-01')->create();

    expect($this->resolver->resolve($this->buyer, MilkType::Buffalo, '2026-09-15')->found)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Isolation
|--------------------------------------------------------------------------
*/

test('one milk type never leaks the other price', function () {
    defaultCow('70.00');
    MilkPriceRule::factory()->for($this->business)->forType(MilkType::Buffalo)
        ->rate('85.00')->period('2026-09-01')->create();

    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '70.00');
    expectMoney($this->resolver->resolve($this->buyer, MilkType::Buffalo, '2026-09-15')->rate(), '85.00');
});

test('one buyers override never leaks to another buyer', function () {
    defaultCow('70.00');
    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-09-01')->create();

    expectMoney($this->resolver->resolve($this->other, MilkType::Cow, '2026-09-15')->rate(), '70.00');
});

test('one business default never leaks to a buyer of another business', function () {
    $otherBusiness = Business::factory()->create();
    $otherChannel = SalesChannel::factory()->for($otherBusiness)->create();
    $foreignBuyer = Buyer::factory()->inChannel($otherChannel)->create();

    defaultCow('70.00');

    // The foreign buyer's business has no price at all.
    expect($this->resolver->resolve($foreignBuyer, MilkType::Cow, '2026-09-15')->found)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Metadata for the sale workflows that will consume this
|--------------------------------------------------------------------------
*/

test('a resolved price reports which rule it came from', function () {
    $default = defaultCow('70.00', '2026-09-01', '2026-09-30');

    $price = $this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15');

    expect($price->ruleId)->toBe($default->id)
        ->and($price->source)->toBe('business_default')
        ->and($price->effectiveFrom)->toBe('2026-09-01')
        ->and($price->effectiveTo)->toBe('2026-09-30')
        ->and($price->milkType)->toBe(MilkType::Cow);
});

test('resolution is memoised per request but can be cleared', function () {
    defaultCow('70.00');

    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '70.00');

    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('72.00')->period('2026-09-01')->create();

    // Still the memoised answer within the same request.
    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '70.00');

    $this->resolver->forget();

    expectMoney($this->resolver->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(), '72.00');
});

test('the resolver is one shared instance per request, so its memo is actually shared', function () {
    /*
     * The memo above is only worth having if every collaborator in a request uses the
     * same instance. It is bound as a singleton in `AppServiceProvider` for exactly
     * that reason, and without the binding the memo would quietly do nothing: the
     * daily entry grid primes every rate up front, then each cell's save resolves
     * again — through a different object, and therefore two more queries per cell.
     *
     * Asserted here rather than left to the docblock, because the binding is one line
     * that nothing else would miss if it were deleted.
     */
    expect(app(PriceResolver::class))->toBe(app(PriceResolver::class));

    // A memo filled through one resolution is visible through another resolve of the
    // container, which is the property the binding exists to provide.
    defaultCow('70.00');

    app(PriceResolver::class)->resolve($this->buyer, MilkType::Cow, '2026-09-15');

    BuyerPriceRule::factory()->for($this->buyer)->forType(MilkType::Cow)
        ->rate('99.00')->period('2026-09-01')->create();

    expectMoney(
        app(PriceResolver::class)->resolve($this->buyer, MilkType::Cow, '2026-09-15')->rate(),
        '70.00',
        'A second container resolution did not see the first one\'s memo, so the resolver is not shared.'
    );

    // And a class that injects it receives the same object the container holds.
    $injected = app(CreateMilkSale::class);
    $reflected = new ReflectionProperty($injected, 'prices');

    expect($reflected->getValue($injected))->toBe(app(PriceResolver::class));
});

test('a sale snapshots the resolved rate rather than referencing the rule', function () {
    /*
     * Until Phase 4 this asserted that no sale existed to consume the resolver, the
     * point being that pricing was settled before anything depended on it. Phase 4
     * made `milk_sales` the consumer, so the guard now checks what that dependency
     * has to look like: the sale carries its own `unit_rate` column and holds **no
     * foreign key to a price rule**.
     *
     * A reference would make every historical sale re-price itself the moment a rule
     * was corrected. A snapshot cannot.
     */
    expect(Schema::hasTable('milk_sales'))->toBeTrue()
        ->and(Schema::hasColumn('milk_sales', 'unit_rate'))->toBeTrue()
        ->and(Schema::getColumnListing('milk_sales'))
        ->not->toContain('milk_price_rule_id')
        ->not->toContain('buyer_price_rule_id')
        ->not->toContain('price_rule_id');
});
