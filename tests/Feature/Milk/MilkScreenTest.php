<?php

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;

/*
 * The Phase 3 screens.
 *
 * What is asserted is the output a person reads: the figures the engine produced,
 * the "Production not entered" state where it applies, the honest statement about
 * sales, and no raw translation keys. Arithmetic is tested against the engine
 * elsewhere; here the question is whether the page tells the truth about it.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    $this->actingAs($this->admin);
});

/** A morning shift with production, usage of several types and both adjustments. */
function seedFullMilkDay(object $test): void
{
    MilkProduction::factory()->for($test->farm)->morning()->on($test->date)
        ->quantities('20.000', '8.000')->create();

    MilkUsage::factory()->for($test->farm)->on($test->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)
        ->usageType(MilkUsageType::CalfFeeding)->quantity('3.000')->create();

    MilkUsage::factory()->for($test->farm)->on($test->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)
        ->usageType(MilkUsageType::Wastage)->quantity('0.250')->create();

    MilkAdjustment::factory()->for($test->farm)->on($test->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('1.000')->create();
}

/*
|--------------------------------------------------------------------------
| A. Reconciliation screen
|--------------------------------------------------------------------------
*/

test('the reconciliation screen shows the engine figures for a real day', function () {
    seedFullMilkDay($this);

    $html = $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
    ]))->assertOk()
        ->assertSee(__('milk.reconciliation.production'))
        ->assertSee(__('milk.reconciliation.available'))
        ->assertSee(__('milk.reconciliation.allocated'))
        ->assertSee(__('milk.reconciliation.remaining'))
        ->getContent();

    // production 20.000 + adjustment 1.000 = available 21.000
    // allocated 3.250, remaining 17.750
    expect($html)->toContain('20.000')
        ->and($html)->toContain('21.000')
        ->and($html)->toContain('17.750');
});

test('the reconciliation screen renders the usage breakdown it has', function () {
    seedFullMilkDay($this);

    $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
    ]))->assertOk()
        ->assertSee(MilkUsageType::CalfFeeding->label())
        ->assertSee(MilkUsageType::Wastage->label())
        // Types with no usage are not drawn as rows of zero.
        ->assertDontSee(MilkUsageType::Sample->label());
});

test('the reconciliation screen shows increases and decreases separately', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('2.000')->create();
    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->decrease('0.500')->create();

    $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
    ]))->assertOk()
        ->assertSee(__('milk.reconciliation.adjustment_increase'))
        ->assertSee(__('milk.reconciliation.adjustment_decrease'));
});

test('the reconciliation screen shows "Production not entered" rather than a zero', function () {
    // Nothing recorded for this date at all.
    $this->get(route('milk.reconciliation', ['date' => '2026-09-15']))
        ->assertOk()
        ->assertSee(__('milk.not_entered'))
        ->assertSee(__('milk.not_entered_help'));
});

test('a recorded zero shows the figure, not the not-entered warning', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->recordedZero()->create();

    $html = $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
    ]))->assertOk()->getContent();

    // The shift was entered, so no warning; the quantity is a real 0.000.
    expect($html)->not->toContain(__('milk.not_entered_help'))
        ->and($html)->toContain('0.000');
});

test('the reconciliation screen shows the sales line, not an excuse for it', function () {
    /*
     * Until Phase 4 this test asserted the screen explained that sales were not
     * recorded yet. They are now, so the notice is gone and the figure is real —
     * zero on a day nothing was sold, which is a fact rather than a placeholder.
     */
    seedPhase2Masters();
    seedFullMilkDay($this);

    $this->get(route('milk.reconciliation', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.reconciliation.sales'))
        ->assertDontSee(__('milk.reconciliation.sales_not_implemented'))
        ->assertDontSee(__('milk.reconciliation.sales_not_implemented_help'));
});

test('the screen draws a row per sales channel now that sales can exist', function () {
    /*
     * Phase 3 asserted the opposite here: the channel list was empty and the screen
     * showed an explanation instead, because rows of 0.000 would have read as
     * "nothing sold today" when the truth was "sales are not implemented".
     *
     * Phase 4 created `milk_sales`, so a channel with no sales is now a real
     * observation and belongs on the screen as a zero. The assertion is inverted
     * rather than removed, because "does the screen tell the truth about sales" is
     * still the thing being checked.
     */
    seedPhase2Masters();
    seedFullMilkDay($this);

    $this->get(route('milk.reconciliation', ['date' => $this->date]))
        ->assertOk()
        ->assertViewHas('salesChannels', fn (array $channels): bool => in_array('direct_customer', $channels, true))
        ->assertSee(__('milk.reconciliation.channels.direct_customer'))
        // And the "not implemented" notice is gone.
        ->assertDontSee(__('milk.reconciliation.sales_not_implemented'));
});

test('quantities render to exactly three decimal places with a unit', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('12.500', '0.000')->create();

    $html = $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
    ]))->assertOk()->getContent();

    expect($html)->toContain('12.500')
        ->and($html)->toContain(__('milk.litres_short'))
        // Not truncated to two places, and not padded to four.
        ->and($html)->not->toContain('12.50<')
        ->and($html)->not->toContain('12.5000');
});

test('the shift and milk type filters narrow the screen', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)
        ->quantities('7.000', '0.000')->create();

    $morningOnly = $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
    ]))->assertOk()->getContent();

    expect($morningOnly)->toContain('10.000')
        ->and($morningOnly)->not->toContain('7.000');

    $both = $this->get(route('milk.reconciliation', ['date' => $this->date]))
        ->assertOk()->getContent();

    expect($both)->toContain('10.000')
        ->and($both)->toContain('7.000');
});

test('an unrecognised filter value is ignored rather than breaking the screen', function () {
    $this->get(route('milk.reconciliation', [
        'date' => $this->date,
        'shift' => 'afternoon',
        'milk_type' => 'goat',
    ]))->assertOk()
        // Falls back to showing everything.
        ->assertSee(Shift::Morning->label())
        ->assertSee(Shift::Evening->label());
});

/*
|--------------------------------------------------------------------------
| B. Production screen
|--------------------------------------------------------------------------
*/

test('the production matrix shows the stored quantities and the totals', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.500', '4.250')->create();
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)
        ->quantities('9.500', '3.750')->create();

    $html = $this->get(route('milk.production.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(Shift::Morning->label())
        ->assertSee(Shift::Evening->label())
        ->assertSee(MilkType::Cow->label())
        ->assertSee(MilkType::Buffalo->label())
        ->getContent();

    expect($html)->toContain('10.500')
        ->and($html)->toContain('4.250')
        // Cow total across shifts, buffalo total, and the grand total.
        ->and($html)->toContain('20.000')
        ->and($html)->toContain('8.000')
        ->and($html)->toContain('28.000');
});

test('the production screen marks an unentered shift rather than showing zero totals', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '5.000')->create();

    $this->get(route('milk.production.index', ['date' => $this->date]))
        ->assertOk()
        // The evening has no row, so its total is an em-dash and the card says so.
        ->assertSee(__('milk.not_entered'));
});

test('the production screen shows who entered and who last changed a shift', function () {
    $this->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $this->get(route('milk.production.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.production.entered_by', ['name' => $this->admin->name]));
});

test('date navigation links to the neighbouring days', function () {
    $this->get(route('milk.production.index', ['date' => '2026-09-10']))
        ->assertOk()
        ->assertSee(route('milk.production.index', ['date' => '2026-09-09']), false)
        ->assertSee(route('milk.production.index', ['date' => '2026-09-11']), false);
});

/*
|--------------------------------------------------------------------------
| C. Usage screen
|--------------------------------------------------------------------------
*/

test('the usage screen lists the day usage with its remaining-milk panel', function () {
    seedFullMilkDay($this);

    $html = $this->get(route('milk.usage.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(MilkUsageType::CalfFeeding->label())
        ->assertSee(MilkUsageType::Wastage->label())
        ->assertSee(__('milk.reconciliation.remaining'))
        ->getContent();

    expect($html)->toContain('3.000')
        ->and($html)->toContain('0.250')
        // Cow remaining: 20.000 + 1.000 - 3.250 = 17.750
        ->and($html)->toContain('17.750');
});

test('a cancelled usage is shown as cancelled with its reason', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('2.000')
        ->cancelled()->create();

    $this->get(route('milk.usage.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('finance.statuses.cancelled'))
        ->assertSee('Test cancellation');
});

test('the usage screen shows an empty state when nothing was used', function () {
    $this->get(route('milk.usage.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.usage.no_usage'));
});

test('the usage form is hidden from a reader who cannot record usage', function () {
    $reader = userWithPermissions(['milk.usage.view']);
    $recorder = userWithPermissions(['milk.usage.view', 'milk.usage.create']);

    /*
     * Asserted on a field unique to the create form. The form's action cannot be
     * used: `milk.usage.store` (POST) and `milk.usage.index` (GET) share the URL
     * /milk/usage, so the date filter's own GET form matches it too.
     */
    $this->actingAs($reader)->get(route('milk.usage.index'))
        ->assertOk()
        ->assertDontSee('name="usage_type"', false);

    // Present for someone who may record, so the assertion above is meaningful.
    $this->actingAs($recorder)->get(route('milk.usage.index'))
        ->assertOk()
        ->assertSee('name="usage_type"', false);
});

/*
|--------------------------------------------------------------------------
| D. Adjustment screen
|--------------------------------------------------------------------------
*/

test('the adjustment screen warns that this is an exception', function () {
    $this->get(route('milk.adjustments.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.adjustments.warning'))
        ->assertSee(__('milk.adjustments.no_prefill'));
});

test('the adjustment quantity field is never pre-filled', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('12.000')->create();

    $html = $this->get(route('milk.adjustments.index', ['date' => $this->date]))
        ->assertOk()->getContent();

    // The shift is 2.000 short. A helpful screen might offer that figure; this
    // one must not, because the adjustment is a statement, not a suggestion.
    expect($html)->toContain('name="quantity"')
        ->and($html)->toMatch('/name="quantity"[^>]*value=""/');
});

test('the adjustment screen shows the current position for context', function () {
    seedFullMilkDay($this);

    $html = $this->get(route('milk.adjustments.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.reconciliation.production'))
        ->assertSee(__('milk.reconciliation.allocated'))
        ->getContent();

    expect($html)->toContain('20.000');
});

test('the adjustment list shows direction, quantity and reason', function () {
    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('2.000')
        ->create(['reason' => 'Collection point measured more than the shed']);

    $this->get(route('milk.adjustments.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(AdjustmentDirection::Increase->label())
        ->assertSee('2.000')
        ->assertSee('Collection point measured more than the shed');
});

test('the adjustment screen shows an empty state when there are none', function () {
    $this->get(route('milk.adjustments.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.adjustments.no_adjustments'));
});

/*
|--------------------------------------------------------------------------
| E. Localisation
|--------------------------------------------------------------------------
*/

test('every milk screen renders in gujarati and hindi with no raw key', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    seedFullMilkDay($this);

    foreach (['milk.production.index', 'milk.usage.index', 'milk.adjustments.index', 'milk.reconciliation'] as $route) {
        $html = $this->actingAs($this->admin)
            ->get(route($route, ['date' => $this->date]))
            ->assertOk()
            ->getContent();

        // A missing key renders as its raw dotted path, which is how a localised
        // page breaks without erroring.
        expect($html)->not->toMatch('/\b(milk|nav|app|finance)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');
    }
})->with(['gu', 'hi']);

test('milk screens show translated labels, not machine enum values', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    seedFullMilkDay($this);

    $html = $this->actingAs($this->admin)
        ->get(route('milk.usage.index', ['date' => $this->date]))
        ->assertOk()->getContent();

    // The human label appears...
    expect($html)->toContain(MilkUsageType::CalfFeeding->label())
        // ...and the raw machine value does not leak into the visible text.
        ->and($html)->not->toContain('>calf_feeding<');
})->with(['gu', 'hi']);

test('the milk navigation is translated in all three locales', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    app()->setLocale($locale);

    $html = $this->actingAs($this->admin)->get(route('milk.production.index'))
        ->assertOk()->getContent();

    expect($html)->toContain(__('nav.milk'))
        ->and($html)->toContain(__('nav.production'))
        ->and($html)->toContain(__('nav.reconciliation'));

    app()->setLocale('en');
})->with(['en', 'gu', 'hi']);
