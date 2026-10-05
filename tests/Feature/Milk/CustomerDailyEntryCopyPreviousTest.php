<?php

use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\AuditLog;
use App\Models\MilkPriceRule;
use App\Models\MilkSale;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\PriceResolver;
use Illuminate\Support\Facades\DB;

/*
 * Copy Previous Day.
 *
 * A convenience that must never become an automation. It reads yesterday, hands
 * the figures to the browser, and stops — no sale, no audit record, no receivable
 * until the operator looks at what was copied and presses Save Day.
 *
 * The eligibility cases below matter more than they look: a customer who has since
 * been paused or archived must not have a quantity staged into their row, because
 * the operator would then press Save Day on a value the server is bound to refuse.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->yesterday = '2026-10-09';
    $this->today = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->yesterday, cow: '100.000', buffalo: '100.000');
    seedProductionFor($this->farm, $this->today, cow: '100.000', buffalo: '100.000');

    $this->cell = app(SaveCustomerDailySale::class);

    $this->actingAs(superAdmin());

    $this->copy = fn (?string $date = null) => $this->getJson(
        route('milk.customer-entry.copy-previous', ['date' => $date ?? $this->today])
    );
});

/*
|--------------------------------------------------------------------------
| A. What gets copied
|--------------------------------------------------------------------------
*/

test('yesterday morning and evening are both offered', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.500');
    $this->cell->handle($customer, $this->yesterday, Shift::Evening, MilkType::Cow, '2.000');

    ($this->copy)()
        ->assertOk()
        ->assertJsonPath('source_date', $this->yesterday)
        ->assertJsonPath("quantities.{$customer->id}:cow.morning", '1.500')
        ->assertJsonPath("quantities.{$customer->id}:cow.evening", '2.000');
});

test('cow and buffalo are copied independently', function () {
    $customer = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');
    $this->cell->handle($customer, $this->yesterday, Shift::Evening, MilkType::Buffalo, '2.500');

    $body = ($this->copy)()->assertOk()->json('quantities');

    expect($body["{$customer->id}:cow"])->toBe(['morning' => '1.000'])
        ->and($body["{$customer->id}:buffalo"])->toBe(['evening' => '2.500']);
});

test('a shift with no delivery yesterday is absent rather than zero', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');

    $body = ($this->copy)()->assertOk()->json('quantities');

    expect($body["{$customer->id}:cow"])->toBe(['morning' => '1.000'])
        ->and($body["{$customer->id}:cow"])->not->toHaveKey('evening');
});

test('a customer with nothing yesterday is left out entirely', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);

    expect($customer->exists)->toBeTrue();
});

test('a cancelled delivery yesterday is not resurrected', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $sale = $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');
    app(CancelMilkSale::class)->becauseRemovedFromGrid($sale);

    // The delivery was withdrawn. Copying it forward would quietly undo that.
    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);
});

test('only the previous calendar day is read, never an older one', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    seedProductionFor($this->farm, '2026-10-08', cow: '100.000', buffalo: '100.000');
    $this->cell->handle($customer, '2026-10-08', Shift::Morning, MilkType::Cow, '9.000');

    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);
});

test('only grid sales are copied, never another channel', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    // A sale on the same day from a different source: Phase 5 territory, and not
    // something the customer grid may pick up.
    MilkSale::factory()->create([
        'farm_id' => $this->farm->id,
        'buyer_id' => $customer->id,
        'sales_channel_id' => $customer->sales_channel_id,
        'sale_date' => $this->yesterday,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '7.000',
        'source' => SaleSource::CustomerDailyGrid->value,
    ]);

    // Re-saved through the grid identity it would clash with, so instead assert on
    // what the copy returns for a source the grid does not own.
    DB::table('milk_sales')->update(['source' => 'mandali_collection']);

    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);
});

/*
|--------------------------------------------------------------------------
| B. Who gets copied into
|--------------------------------------------------------------------------
*/

test('a customer paused today is not offered a copied quantity', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');

    app(CreateCustomerPause::class)->handle($customer, $this->today, null, 'Away');

    // Still on the grid, marked — but with nothing staged into a closed field.
    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);
});

test('a customer archived since yesterday is absent from the grid and the copy', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');
    $customer->update(['is_active' => false]);

    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);
});

test('a milk type the customer no longer takes is not copied', function () {
    $customer = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');
    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Buffalo, '2.000');

    $customer->preferences()->where('milk_type', MilkType::Buffalo->value)->update(['is_active' => false]);

    $body = ($this->copy)()->assertOk()->json('quantities');

    expect($body)->toHaveKey("{$customer->id}:cow")
        ->and($body)->not->toHaveKey("{$customer->id}:buffalo");
});

test('a row with no price today is not offered a copy it could not save', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');

    // The cow price is withdrawn, so a new delivery today cannot be priced. The
    // row is not editable, so nothing is staged into it.
    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    ($this->copy)()->assertOk()->assertJsonPath('quantities', []);
});

/*
|--------------------------------------------------------------------------
| C. It writes nothing
|--------------------------------------------------------------------------
*/

test('copying writes no sale, no audit record and no receivable', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.500');

    $before = [
        'sales' => MilkSale::query()->count(),
        'audits' => AuditLog::query()->count(),
        'outstanding' => app(BuyerOutstandingService::class)
            ->breakdownFor($customer)['outstanding'],
    ];

    ($this->copy)()->assertOk();

    expect(MilkSale::query()->count())->toBe($before['sales'])
        ->and(AuditLog::query()->count())->toBe($before['audits']);

    expectMoney(
        app(BuyerOutstandingService::class)->breakdownFor($customer)['outstanding'],
        $before['outstanding'],
    );

    // Nothing was created for today.
    expect(MilkSale::query()->whereDate('sale_date', $this->today)->count())->toBe(0);
});

test('the copy endpoint is a read, and the rate is deliberately not part of it', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.000');

    $body = ($this->copy)()->assertOk()->json();

    /*
     * Quantities only. Yesterday's rate and amount are deliberately absent: a
     * copied delivery is a new sale on today's date and must be priced by today's
     * resolution, not by what applied the day before a price change.
     */
    expect($body['quantities']["{$customer->id}:cow"])->toBe(['morning' => '1.000']);
    expect(json_encode($body))->not->toContain('rate')->not->toContain('amount');
});

test('nothing copies automatically when the grid is simply opened', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '1.500');

    $html = $this->get(route('milk.customer-entry.index', ['date' => $this->today]))
        ->assertOk()->getContent();

    // Today's fields are empty even though yesterday had a delivery, and nothing
    // was written by the page load.
    preg_match_all('/<input[^>]*data-entry-input[^>]*>/', $html, $inputs);

    foreach ($inputs[0] as $input) {
        expect($input)->toMatch('/value=""/');
    }

    expect(MilkSale::query()->whereDate('sale_date', $this->today)->count())->toBe(0);
});

test('the copy only happens on an explicit click, and leaves the form unsaved', function () {
    $source = file_get_contents(resource_path('js/customer-daily-entry.js'));

    // Bound to a click, and to nothing that fires on load.
    expect($source)->toContain("this.copyButton?.addEventListener('click'")
        ->toContain('this.markDirty();')
        // The copy fills fields and never posts.
        ->and($source)->not->toContain('this.save();\n        }\n    }\n\n    async copyPreviousDay');

    $copySection = substr($source, (int) strpos($source, 'async copyPreviousDay'));
    $copySection = substr($copySection, 0, (int) strpos($copySection, 'setBusy(busy)'));

    expect($copySection)->not->toContain("method: 'POST'")
        ->and($copySection)->not->toContain('this.save(');
});

/*
|--------------------------------------------------------------------------
| D. After copying, the save behaves like any other
|--------------------------------------------------------------------------
*/

test('copied quantities price at the selected date, not yesterday', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($customer, $this->yesterday, Shift::Morning, MilkType::Cow, '2.000');

    // The rate changes between the two days.
    app(SetMilkPrice::class)->forBusiness(
        $this->business, MilkType::Cow, '80.00', $this->today
    );
    app(PriceResolver::class)->forget();

    $copied = ($this->copy)()->assertOk()->json("quantities.{$customer->id}:cow.morning");

    expect($copied)->toBe('2.000');

    $this->postJson(route('milk.customer-entry.store'), [
        'date' => $this->today,
        'rows' => [['buyer_id' => $customer->id, 'milk_type' => 'cow', 'morning' => $copied, 'evening' => null]],
    ])->assertOk();

    $today = MilkSale::query()->whereDate('sale_date', $this->today)->firstOrFail();
    $yesterdaySale = MilkSale::query()->whereDate('sale_date', $this->yesterday)->firstOrFail();

    expectMoney($today->unit_rate, '80.00');
    expectMoney($today->amount, '160.00');

    // And yesterday is untouched by any of it.
    expectMoney($yesterdaySale->unit_rate, '70.00');
});
