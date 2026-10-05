<?php

use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\SalesChannel;
use App\Services\Milk\CustomerDailyEntryGrid;
use App\Services\PriceResolver;

/*
 * The Customer Daily Entry screen: who appears, what the fields contain, and the
 * one rule the whole feature turns on — a reminder is never a quantity.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->grid = app(CustomerDailyEntryGrid::class);
    $this->url = route('milk.customer-entry.index', ['date' => $this->date]);

    $this->actingAs(superAdmin());
});

/*
|--------------------------------------------------------------------------
| A. Who appears
|--------------------------------------------------------------------------
*/

test('the grid loads every eligible direct customer without pagination', function () {
    foreach (range(1, 30) as $i) {
        directCustomer($this->business, [MilkType::Cow], ['name' => "Customer {$i}"]);
    }

    $response = $this->get($this->url)->assertOk();

    // All thirty, not a first page of them, and no pagination control at all.
    foreach (range(1, 30) as $i) {
        $response->assertSee("Customer {$i}");
    }

    expect($response->getContent())->not->toContain('pagination');
});

test('a customer taking both milk types gets a row for each', function () {
    directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo], ['name' => 'Rajesh Patel']);

    $rows = $this->grid->rowsFor($this->date, $this->farm->id);

    expect($rows)->toHaveCount(2)
        ->and($rows->map(fn ($row): string => $row->milkType->value)->sort()->values()->all())
        ->toBe(['buffalo', 'cow']);
});

test('a buffalo-only customer has no cow row to fill in by mistake', function () {
    directCustomer($this->business, [MilkType::Buffalo], ['name' => 'Mahesh']);

    $rows = $this->grid->rowsFor($this->date, $this->farm->id);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->milkType)->toBe(MilkType::Buffalo);
});

test('customers who cannot take a delivery on this date do not appear', function () {
    directCustomer($this->business, [MilkType::Cow], ['name' => 'Active Customer']);
    directCustomer($this->business, [MilkType::Cow], ['name' => 'Archived Customer', 'is_active' => false]);
    directCustomer($this->business, [MilkType::Cow], ['name' => 'Future Customer', 'start_date' => '2026-12-01']);

    $noPreference = directCustomer($this->business, [MilkType::Cow], ['name' => 'Lapsed Customer']);
    $noPreference->preferences()->update(['is_active' => false]);

    $this->get($this->url)
        ->assertOk()
        ->assertSee('Active Customer')
        ->assertDontSee('Archived Customer')
        ->assertDontSee('Future Customer')
        ->assertDontSee('Lapsed Customer');
});

test('a customer whose start date is the selected day does appear', function () {
    directCustomer($this->business, [MilkType::Cow], [
        'name' => 'Starts Today', 'start_date' => $this->date,
    ]);

    $this->get($this->url)->assertOk()->assertSee('Starts Today');
});

test('buyers from other channels never reach the grid', function () {
    $mandali = SalesChannel::query()->where('slug', SalesChannel::MANDALI)->firstOrFail();
    $vendor = SalesChannel::query()->where('slug', SalesChannel::VENDOR)->firstOrFail();

    Buyer::factory()->inChannel($mandali)->create(['name' => 'Village Mandali']);
    Buyer::factory()->inChannel($vendor)->create(['name' => 'Local Dairy']);
    directCustomer($this->business, [MilkType::Cow], ['name' => 'Rajesh Patel']);

    $this->get($this->url)
        ->assertOk()
        ->assertSee('Rajesh Patel')
        ->assertDontSee('Village Mandali')
        ->assertDontSee('Local Dairy');
});

/*
|--------------------------------------------------------------------------
| B. Reminders are never quantities
|--------------------------------------------------------------------------
*/

test('the quantity inputs are empty when nothing has been delivered, however large the reminder', function () {
    customerWithReminder($this->business, MilkType::Cow, '1.000', '2.000', ['name' => 'Rajesh Patel']);

    $html = $this->get($this->url)->assertOk()->getContent();

    /*
     * The assertion that matters in the whole phase. The reminder has to be on the
     * page as text, and the two inputs have to be empty — not defaulted, not
     * placeholdered with a figure, not disabled with a value behind them.
     */
    preg_match_all('/<input[^>]*data-entry-input[^>]*>/', $html, $inputs);

    expect($inputs[0])->toHaveCount(2);

    foreach ($inputs[0] as $input) {
        expect($input)->toMatch('/value=""/')
            ->and($input)->not->toMatch('/placeholder="[0-9]/');
    }
});

test('the reminder is rendered beside the fields rather than inside them', function () {
    customerWithReminder($this->business, MilkType::Cow, '1.500', '2.500', ['name' => 'Rajesh Patel']);

    $html = $this->get($this->url)->assertOk()->getContent();

    // Present as helper text...
    expect($html)->toContain('1.500')->toContain('2.500');

    // ...and not as the value of any entry field.
    preg_match_all('/<input[^>]*data-entry-input[^>]*>/', $html, $inputs);

    foreach ($inputs[0] as $input) {
        expect($input)->not->toContain('value="1.500"')
            ->and($input)->not->toContain('value="2.500"');
    }
});

test('a customer with no reminder says so rather than showing a zero', function () {
    customerWithReminder($this->business, MilkType::Cow, '0.000', '0.000', ['name' => 'Rajesh Patel']);

    $this->get($this->url)->assertOk()->assertSee(__('milk.customer_entry.reminder_none'));
});

test('no view or javascript reads a reminder as a quantity', function () {
    /*
     * A source guard, because this is the rule most likely to be broken by a
     * well-meaning edit six months from now. The grid's templates and its module
     * must not mention the reminder columns at all.
     */
    $sources = [
        resource_path('views/milk/customer-entry/index.blade.php'),
        resource_path('views/milk/customer-entry/_row.blade.php'),
        resource_path('js/customer-daily-entry.js'),
    ];

    foreach ($sources as $path) {
        $source = file_get_contents($path);

        expect($source)->not->toContain('morning_reminder_qty')
            ->not->toContain('evening_reminder_qty')
            ->not->toContain('morningReminder()')
            ->not->toContain('eveningReminder()');
    }
});

/*
|--------------------------------------------------------------------------
| C. Saved values do prefill
|--------------------------------------------------------------------------
*/

test('reopening a saved day shows the quantities that were recorded', function () {
    $customer = customerWithReminder($this->business, MilkType::Cow, '1.000', '2.000', ['name' => 'Rajesh Patel']);

    app(SaveCustomerDailySale::class)
        ->forDay($customer, $this->date, MilkType::Cow, ['morning' => '1.500', 'evening' => '0.750']);

    $html = $this->get($this->url)->assertOk()->getContent();

    // The actual figures are in the fields...
    expect($html)->toContain('value="1.500"')->toContain('value="0.750"')
        // ...and the reminder is still only text beside them.
        ->and($html)->toContain('1.000')->toContain('2.000');
});

test('a cancelled delivery leaves the field empty rather than showing the withdrawn figure', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    $save = app(SaveCustomerDailySale::class);

    $save->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $save->handle($customer, $this->date, Shift::Morning, MilkType::Cow, null);

    $rows = $this->grid->rowsFor($this->date, $this->farm->id);

    expect($rows->first()->savedQuantity(Shift::Morning))->toBeNull()
        // The row itself is still there, so re-entering reuses its identity.
        ->and($rows->first()->saleFor(Shift::Morning))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| D. Paused customers
|--------------------------------------------------------------------------
*/

test('a paused customer stays visible, is marked, and has closed fields', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['name' => 'Rajesh Patel']);

    app(CreateCustomerPause::class)->handle($customer, '2026-10-01', '2026-10-31', 'Away');

    $response = $this->get($this->url)->assertOk();

    $response->assertSee('Rajesh Patel')
        ->assertSee(__('milk.customer_entry.statuses.paused'))
        ->assertSee(__('milk.customer_entry.paused_help'));

    $rows = $this->grid->rowsFor($this->date, $this->farm->id);

    expect($rows->first()->isPaused())->toBeTrue()
        ->and($rows->first()->isEditable())->toBeFalse();

    // The input is rendered disabled, which is a courtesy; the server refuses too.
    expect($response->getContent())->toMatch('/<input[^>]*data-entry-input[^>]*disabled/');
});

test('there is no pause override control on the grid', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(CreateCustomerPause::class)->handle($customer, '2026-10-01', null, 'Away');

    $html = $this->get($this->url)->assertOk()->getContent();

    // The specification permits an authorised override; it does not require one,
    // and inventing one to fill a gap would be functionality nobody asked for.
    expect(strtolower($html))->not->toContain('override');
});

/*
|--------------------------------------------------------------------------
| E. Pricing on the screen
|--------------------------------------------------------------------------
*/

test('a row with no resolvable price says so instead of showing a zero rate', function () {
    // Buffalo has a price; cow does not, on this date.
    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();

    directCustomer($this->business, [MilkType::Cow], ['name' => 'Rajesh Patel']);

    $response = $this->get($this->url)->assertOk();

    $response->assertSee(__('milk.customer_entry.price_missing'))
        ->assertSee(__('milk.customer_entry.price_missing_help'));

    $rows = $this->grid->rowsFor($this->date, $this->farm->id);

    expect($rows->first()->hasPrice())->toBeFalse()
        ->and($rows->first()->resolvedRate)->toBeNull()
        ->and($rows->first()->isEditable())->toBeFalse()
        ->and($rows->first()->status())->toBe('price_missing');
});

test('an unpriced row with an existing sale stays editable on its own snapshot', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(SaveCustomerDailySale::class)
        ->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    // The price is withdrawn afterwards. The recorded sale keeps its own rate.
    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $rows = app(CustomerDailyEntryGrid::class)->rowsFor($this->date, $this->farm->id);
    $row = $rows->first();

    expect($row->hasPrice())->toBeFalse()
        // Still editable, because its quantity recomputes against its own rate and
        // clearing it needs no price at all.
        ->and($row->isEditable())->toBeTrue()
        ->and($row->rateFor(Shift::Morning))->toBe('70.00');
});

test('the rate column shows the stored snapshot rather than a later price', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(SaveCustomerDailySale::class)
        ->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    // A buyer override is added afterwards, covering the same date.
    BuyerPriceRule::factory()->for($customer)->forType(MilkType::Cow)
        ->rate('99.00')->period('2026-10-01')->create();
    app(PriceResolver::class)->forget();

    $row = app(CustomerDailyEntryGrid::class)->rowsFor($this->date, $this->farm->id)->first();

    expect($row->rateFor(Shift::Morning))->toBe('70.00')
        ->and($row->rateIsSnapshot(Shift::Morning))->toBeTrue()
        // The empty evening cell is priced at what applies now.
        ->and($row->rateFor(Shift::Evening))->toBe('99.00')
        ->and($row->hasMixedRates())->toBeTrue();
});

test('there is no manual rate field anywhere on the grid', function () {
    directCustomer($this->business, [MilkType::Cow]);

    $html = $this->get($this->url)->assertOk()->getContent();

    // milk.customer_delivery.override_rate stays unused until an override workflow
    // with its own audit behaviour is designed.
    expect($html)->not->toMatch('/<input[^>]*name="[^"]*rate/')
        ->and($html)->not->toMatch('/<input[^>]*data-entry-rate/');
});

/*
|--------------------------------------------------------------------------
| F. Screen furniture
|--------------------------------------------------------------------------
*/

test('the screen offers date navigation, filters and the two day-level actions', function () {
    directCustomer($this->business, [MilkType::Cow], ['area' => 'North']);

    $response = $this->get($this->url)->assertOk();

    $response->assertSee(__('milk.customer_entry.save_day'))
        ->assertSee(__('milk.customer_entry.copy_previous'))
        ->assertSee(__('milk.customer_entry.search'))
        ->assertSee(__('milk.customer_entry.all_types'))
        ->assertSee(__('milk.customer_entry.all_areas'))
        ->assertSee(__('milk.customer_entry.filter_hint'))
        ->assertSee(route('milk.customer-entry.index', ['date' => '2026-10-09']), false)
        ->assertSee(route('milk.customer-entry.index', ['date' => '2026-10-11']), false);
});

test('the area filter is left out when there are no areas to choose between', function () {
    directCustomer($this->business, [MilkType::Cow], ['area' => null]);

    $this->get($this->url)->assertOk()->assertDontSee(__('milk.customer_entry.all_areas'));
});

test('the screen shows the milk still unallocated for each shift', function () {
    directCustomer($this->business, [MilkType::Cow]);

    $this->get($this->url)->assertOk()
        ->assertSee(__('milk.customer_entry.remaining_milk'))
        ->assertSee(__('milk.customer_entry.remaining_help'));
});

test('a shift with no production is reported as not entered rather than as zero', function () {
    MilkProduction::query()->delete();
    directCustomer($this->business, [MilkType::Cow]);

    $this->get($this->url)->assertOk()->assertSee(__('milk.not_entered_short'));
});

test('a day with no eligible customers shows an empty state rather than an empty table', function () {
    $this->get($this->url)->assertOk()
        ->assertSee(__('milk.customer_entry.empty'))
        ->assertSee(__('milk.customer_entry.empty_help'));
});

test('the footer shows the totals the specification asks for', function () {
    $customer = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);
    $save = app(SaveCustomerDailySale::class);

    $save->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $save->handle($customer, $this->date, Shift::Evening, MilkType::Buffalo, '1.000');

    $rows = $this->grid->rowsFor($this->date, $this->farm->id);
    $totals = $this->grid->totalsFor($rows);

    expect($totals['shifts']['morning']['cow'])->toBe('2.000')
        ->and($totals['shifts']['morning']['buffalo'])->toBe('0.000')
        ->and($totals['shifts']['evening']['buffalo'])->toBe('1.000')
        ->and($totals['quantity'])->toBe('3.000');

    // 2.000 x 70.00 + 1.000 x 85.00
    expectMoney($totals['amount'], '225.00');

    $this->get($this->url)->assertOk()
        ->assertSee(__('milk.customer_entry.overall_quantity'))
        ->assertSee(__('milk.customer_entry.overall_amount'));
});

/*
|--------------------------------------------------------------------------
| G. Localisation
|--------------------------------------------------------------------------
*/

test('the grid renders in Gujarati and Hindi with no raw keys', function (string $locale) {
    customerWithReminder($this->business, MilkType::Cow, '1.000', '2.000', ['name' => 'Rajesh Patel']);

    $user = superAdmin(['locale' => $locale]);

    $html = $this->actingAs($user)->get($this->url)->assertOk()->getContent();

    expect($html)->toContain(__('milk.customer_entry.title', [], $locale))
        ->toContain(__('milk.customer_entry.save_day', [], $locale))
        ->toContain(__('milk.customer_entry.copy_previous', [], $locale))
        ->toContain(__('milk.customer_entry.reminder', [], $locale))
        // No untranslated dotted key leaked through.
        ->not->toContain('milk.customer_entry.')
        ->not->toContain('customers.eligibility.');
})->with(['gu', 'hi']);
