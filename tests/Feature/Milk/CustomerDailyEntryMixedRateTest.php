<?php

use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Models\MilkSale;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Milk\CustomerDailyEntryGrid;
use App\Services\PriceResolver;
use App\Support\Quantity;

/*
 * One grid row, two sale records.
 *
 * A row is a customer and a milk type, but the morning and the evening are separate
 * sales with separate snapshots (D41). Everything here follows from that: the two
 * shifts can be priced differently and both be correct, one can be correctable while
 * the other cannot be created at all, and the row total is the sum of two
 * independently priced halves.
 *
 * The failure these guard against is a tidier-looking grid that quietly assumes one
 * rate per row — which would misreport money on exactly the rows where somebody
 * later fixed a price.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-01';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->customer = directCustomer($this->business, [MilkType::Cow], ['name' => 'Rajesh Patel']);
    $this->cell = app(SaveCustomerDailySale::class);

    $this->actingAs(superAdmin());

    $this->rows = fn () => app(CustomerDailyEntryGrid::class)->rowsFor($this->date, $this->farm->id);

    $this->save = fn (?string $morning, ?string $evening) => $this->postJson(
        route('milk.customer-entry.store'),
        ['date' => $this->date, 'rows' => [[
            'buyer_id' => $this->customer->id,
            'milk_type' => 'cow',
            'morning' => $morning,
            'evening' => $evening,
        ]]],
    );

    // A buyer rate that starts covering a date which may already have deliveries.
    $this->overrideFrom = function (string $rate, string $from): void {
        BuyerPriceRule::factory()->for($this->customer)->forType(MilkType::Cow)
            ->rate($rate)->period($from)->create();

        app(PriceResolver::class)->forget();
    };
});

/*
|--------------------------------------------------------------------------
| A. The scenario from the brief, end to end
|--------------------------------------------------------------------------
*/

test('a morning snapshot and a later override price the two shifts differently', function () {
    // Morning recorded at the business default.
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    // A customer rate is agreed afterwards, covering the same date.
    ($this->overrideFrom)('72.00', $this->date);

    $row = ($this->rows)()->first();

    expect(Quantity::of($row->savedQuantity(Shift::Morning)))->toBe('1.000')
        ->and($row->savedQuantity(Shift::Evening))->toBeNull();

    // The recorded morning keeps what it was recorded at...
    expect($row->rateFor(Shift::Morning))->toBe('70.00')
        ->and($row->rateIsSnapshot(Shift::Morning))->toBeTrue()
        // ...and a new evening would be priced at what applies now.
        ->and($row->rateFor(Shift::Evening))->toBe('72.00')
        ->and($row->rateIsSnapshot(Shift::Evening))->toBeFalse();
});

test('entering the evening gives a row total of two independently priced halves', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);

    ($this->save)('1.000', '1.000')->assertOk();

    $morning = MilkSale::query()->where('shift', Shift::Morning->value)->firstOrFail();
    $evening = MilkSale::query()->where('shift', Shift::Evening->value)->firstOrFail();

    expectMoney($morning->unit_rate, '70.00');
    expectMoney($evening->unit_rate, '72.00');

    // 1.000 x 70.00 + 1.000 x 72.00 — not 2 L at either rate.
    $row = ($this->rows)()->first();

    expectMoney($row->amount(), '142.00');
    expect(Quantity::of($row->total()))->toBe('2.000');

    expectMoney($row->amount(), '142.00');
    expect(bccomp($row->amount(), '140.00', 2))->not->toBe(0)
        ->and(bccomp($row->amount(), '144.00', 2))->not->toBe(0);
});

test('the grid never shows one rate for two different ones', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);

    $row = ($this->rows)()->first();

    // No single figure is offered, because none would be true.
    expect($row->displayRate())->toBeNull()
        ->and($row->hasMixedRates())->toBeTrue();

    $html = $this->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertOk()->getContent();

    // Both rates appear, labelled by shift.
    expect($html)->toContain('data-row-rates')
        ->toContain('70.00')
        ->toContain('72.00');
});

test('a row whose shifts share a rate still shows one figure', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    $row = ($this->rows)()->first();

    expect($row->displayRate())->toBe('70.00')
        ->and($row->hasMixedRates())->toBeFalse();

    $html = $this->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertOk()->getContent();

    expect($html)->not->toContain('data-row-rates');
});

test('the browser is given a rate per cell, not per row', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);

    $payload = ($this->rows)()->first()->toClientPayload();

    // Integer paise, so the live totals add two different prices exactly.
    expect($payload['cells']['morning']['ratePaise'])->toBe(7000)
        ->and($payload['cells']['evening']['ratePaise'])->toBe(7200);
});

/*
|--------------------------------------------------------------------------
| B. Both shifts already recorded, at different rates
|--------------------------------------------------------------------------
*/

test('two historical snapshots on one row both stand exactly as stored', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    ($this->overrideFrom)('72.00', $this->date);

    $this->cell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');

    $row = ($this->rows)()->first();

    expect($row->rateFor(Shift::Morning))->toBe('70.00')
        ->and($row->rateFor(Shift::Evening))->toBe('72.00')
        ->and($row->rateIsSnapshot(Shift::Morning))->toBeTrue()
        ->and($row->rateIsSnapshot(Shift::Evening))->toBeTrue();

    expectMoney($row->amount(), '142.00');
});

test('correcting one shift leaves the other shift rate untouched', function (string $shiftToChange) {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);
    $this->cell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');

    $changed = Shift::from($shiftToChange);
    $untouched = $changed === Shift::Morning ? Shift::Evening : Shift::Morning;

    $before = MilkSale::query()->where('shift', $untouched->value)->firstOrFail();

    ($this->save)(
        $changed === Shift::Morning ? '3.000' : '1.000',
        $changed === Shift::Evening ? '3.000' : '1.000',
    )->assertOk();

    $after = MilkSale::query()->where('shift', $untouched->value)->firstOrFail();

    // The other shift's rate, amount and quantity are all exactly as they were.
    expectMoney($after->unit_rate, Quantity::money($before->unit_rate));
    expectMoney($after->amount, Quantity::money($before->amount));
    expect(Quantity::of($after->quantity))->toBe(Quantity::of($before->quantity));

    // And the changed one re-costs against its own snapshot, not the other's.
    $changedSale = MilkSale::query()->where('shift', $changed->value)->firstOrFail();
    $expected = Quantity::multiplyToMoney('3.000', $changedSale->unit_rate);

    expectMoney($changedSale->amount, $expected);
})->with(['morning', 'evening']);

test('historical snapshots are never normalised to match each other', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);
    $this->cell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');

    // Re-saving the day unchanged, and then changing both, must keep both rates.
    ($this->save)('1.000', '1.000')->assertOk()->assertJsonPath('summary.changed', 0);
    ($this->save)('2.000', '2.000')->assertOk();

    expectMoney(MilkSale::query()->where('shift', Shift::Morning->value)->value('unit_rate'), '70.00');
    expectMoney(MilkSale::query()->where('shift', Shift::Evening->value)->value('unit_rate'), '72.00');

    // 2 x 70 + 2 x 72
    expectMoney(app(BuyerOutstandingService::class)->breakdownFor($this->customer)['outstanding'], '284.00');
});

/*
|--------------------------------------------------------------------------
| C. One shift recorded, no price for a new one
|--------------------------------------------------------------------------
*/

test('a recorded morning stays usable when the price for the date is withdrawn', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $row = ($this->rows)()->first();

    expect($row->hasPrice())->toBeFalse()
        // The morning can still be corrected or cleared on its own snapshot...
        ->and($row->isEditableFor(Shift::Morning))->toBeTrue()
        ->and($row->rateFor(Shift::Morning))->toBe('70.00')
        // ...while a new evening cannot be priced at all.
        ->and($row->isEditableFor(Shift::Evening))->toBeFalse()
        ->and($row->rateFor(Shift::Evening))->toBeNull()
        // The row as a whole is not dead.
        ->and($row->isEditable())->toBeTrue();
});

test('the unpriceable cell is closed while the correctable one stays open', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $html = $this->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertOk()->getContent();

    preg_match('/<input[^>]*data-shift="morning"[^>]*>/', $html, $morning);
    preg_match('/<input[^>]*data-shift="evening"[^>]*>/', $html, $evening);

    expect($morning[0])->not->toContain('disabled')
        ->and($evening[0])->toContain('disabled');
});

test('the morning can be corrected and cleared with no current price', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    // Corrected on its own snapshot: 1.000 x 70.00.
    ($this->save)('1.000', null)->assertOk();
    expectMoney(MilkSale::query()->value('amount'), '70.00');
    expectMoney(MilkSale::query()->value('unit_rate'), '70.00');

    // And cleared without any price being needed.
    ($this->save)(null, null)->assertOk();
    expect(MilkSale::query()->active()->count())->toBe(0);
});

test('a new evening still cannot be created without a price for the date', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    // Hand-written, since the field is disabled on screen.
    ($this->save)('2.000', '1.000')->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(1)
        ->and(Quantity::of(MilkSale::query()->value('quantity')))->toBe('2.000');
});

test('the row is not reported as unpriced when half of it is recorded', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $row = ($this->rows)()->first();

    // "Price not configured" would be wrong: there is a delivery here.
    expect($row->status())->toBe('saved')
        ->and($row->hasMixedRates())->toBeTrue()
        ->and($row->displayRate())->toBeNull();
});

test('copy previous day does not stage a value into an unpriceable cell', function () {
    $yesterday = '2026-09-30';
    seedProductionFor($this->farm, $yesterday, cow: '100.000', buffalo: '100.000');

    $this->cell->handle($this->customer, $yesterday, Shift::Morning, MilkType::Cow, '1.000');
    $this->cell->handle($this->customer, $yesterday, Shift::Evening, MilkType::Cow, '2.000');

    // Today: the morning already exists, and the price is then withdrawn.
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $body = $this->getJson(route('milk.customer-entry.copy-previous', ['date' => $this->date]))
        ->assertOk()->json('quantities');

    $key = "{$this->customer->id}:cow";

    // The morning is offered because it can be saved; the evening is not.
    expect($body[$key] ?? [])->toBe(['morning' => '1.000']);
});

/*
|--------------------------------------------------------------------------
| D. The ledger must not flatten two rates either
|--------------------------------------------------------------------------
*/

test('the customer statement reports both rates rather than inventing one', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);
    $this->cell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');

    $statement = app(CustomerLedgerService::class)
        ->statement($this->customer, $this->date, $this->date);

    $row = $statement['rows']->firstWhere('kind', 'sale');

    // One statement line for the day, carrying both rates and the true amount.
    expect($row->rate)->toBeNull()
        ->and($row->rates)->toBe(['70.00', '72.00']);

    expectMoney($row->amount, '142.00');
    expectMoney($statement['closing'], '142.00');
});

/*
|--------------------------------------------------------------------------
| E. A pause created afterwards still does not rewrite history
|--------------------------------------------------------------------------
*/

test('a pause covering a recorded day leaves both shifts and both rates alone', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    ($this->overrideFrom)('72.00', $this->date);
    $this->cell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');

    $before = MilkSale::query()->orderBy('shift')->get()
        ->map(fn (MilkSale $s): array => [
            $s->shift->value, Quantity::of($s->quantity), Quantity::money($s->unit_rate), $s->status->value,
        ])->all();

    // The pause is created now, backdated over the recorded day.
    app(CreateCustomerPause::class)->handle($this->customer, '2026-09-01', '2026-10-31', 'Away');

    // Loading the day changes nothing...
    $this->get(route('milk.customer-entry.index', ['date' => $this->date]))->assertOk();

    // ...nor does re-saving it unchanged...
    ($this->save)('1.000', '1.000')->assertOk()->assertJsonPath('summary.changed', 0);

    // ...nor does asking Copy Previous Day for it.
    $this->getJson(route('milk.customer-entry.copy-previous', ['date' => $this->date]))->assertOk();

    $after = MilkSale::query()->orderBy('shift')->get()
        ->map(fn (MilkSale $s): array => [
            $s->shift->value, Quantity::of($s->quantity), Quantity::money($s->unit_rate), $s->status->value,
        ])->all();

    expect($after)->toBe($before);

    // The money is untouched too.
    expectMoney(app(BuyerOutstandingService::class)->breakdownFor($this->customer)['outstanding'], '142.00');
});

test('the paused day refuses a change to either shift without touching the other', function () {
    $this->cell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    $this->cell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');

    app(CreateCustomerPause::class)->handle($this->customer, '2026-09-01', '2026-10-31', 'Away');

    ($this->save)('5.000', '1.000')->assertStatus(422);

    expect(MilkSale::query()->active()->count())->toBe(2);

    foreach (MilkSale::query()->get() as $sale) {
        expect(Quantity::of($sale->quantity))->toBe('1.000');
    }
});
