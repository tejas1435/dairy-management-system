<?php

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3 Pass 1 smoke tests.
 *
 * These prove the milk module is navigable and that its few load-bearing
 * mechanisms are wired up: the pages render, the production save reaches one
 * deterministic row, availability is enforced, and an adjustment demands a reason.
 *
 * They are deliberately not the Phase 3 business suite. Reconciliation arithmetic
 * across shifts and milk types, three-decimal exactness, the full missing-versus-zero
 * matrix, cancellation effects on allocation and the permission grid all belong to
 * Pass 2. A page that renders is not a page that is correct; this file only rules
 * out the class of failure where something 500s or silently does nothing.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->today = now()->toDateString();
});

/*
|--------------------------------------------------------------------------
| Pages render
|--------------------------------------------------------------------------
*/

test('every milk page renders', function (string $routeName) {
    $this->actingAs($this->admin)->get(route($routeName))->assertOk();
})->with([
    'milk.production.index',
    'milk.usage.index',
    'milk.adjustments.index',
    'milk.reconciliation',
]);

test('the milk pages render with real records on them', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)
        ->quantities('12.500', '6.250')->create();

    MilkUsage::factory()->for($this->farm)->on($this->today)
        ->usageType(MilkUsageType::CalfFeeding)->quantity('2.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->today)
        ->increase('0.500')->create();

    foreach (['milk.production.index', 'milk.usage.index', 'milk.adjustments.index', 'milk.reconciliation'] as $route) {
        $this->actingAs($this->admin)->get(route($route, ['date' => $this->today]))->assertOk();
    }
});

test('the reconciliation page renders for a filtered shift and milk type', function () {
    $this->actingAs($this->admin)
        ->get(route('milk.reconciliation', [
            'date' => $this->today,
            'shift' => Shift::Evening->value,
            'milk_type' => MilkType::Buffalo->value,
        ]))
        ->assertOk()
        ->assertSee(Shift::Evening->label());
});

test('a nonsense date parameter shows today rather than failing', function () {
    $this->actingAs($this->admin)
        ->get(route('milk.production.index', ['date' => 'not-a-date']))
        ->assertOk();
});

test('every milk page renders in gujarati and hindi without a raw key', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();

    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)->create();
    MilkUsage::factory()->for($this->farm)->on($this->today)->create();
    MilkAdjustment::factory()->for($this->farm)->on($this->today)->create();

    foreach (['milk.production.index', 'milk.usage.index', 'milk.adjustments.index', 'milk.reconciliation'] as $route) {
        $html = $this->actingAs($this->admin)
            ->get(route($route, ['date' => $this->today]))
            ->assertOk()
            ->getContent();

        // A missing key renders as the raw dotted string, which is the usual way
        // a localised page breaks without erroring.
        expect($html)->not->toMatch('/\b(milk|nav|app)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');
    }
})->with(['gu', 'hi']);

/*
|--------------------------------------------------------------------------
| Production saves to one deterministic row
|--------------------------------------------------------------------------
*/

test('saving production creates one row per shift with both milk types on it', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->today,
        'shifts' => [
            'morning' => ['cow' => '12.500', 'buffalo' => '6.250'],
            'evening' => ['cow' => '11.000', 'buffalo' => '5.750'],
        ],
    ])->assertRedirect();

    expect(MilkProduction::query()->count())->toBe(2);

    $morning = MilkProduction::query()->where('shift', Shift::Morning->value)->firstOrFail();

    expect($morning->cow_milk_quantity)->toBe('12.500')
        ->and($morning->buffalo_milk_quantity)->toBe('6.250')
        ->and($morning->farm_id)->toBe($this->farm->id);
});

test('saving the same farm date and shift twice updates the row instead of adding one', function () {
    $payload = [
        'production_date' => $this->today,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '5.000']],
    ];

    $this->actingAs($this->admin)->post(route('milk.production.store'), $payload)->assertRedirect();
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->today,
        'shifts' => ['morning' => ['cow' => '14.000', 'buffalo' => '7.000']],
    ])->assertRedirect();

    expect(MilkProduction::query()->count())->toBe(1);

    $row = MilkProduction::query()->firstOrFail();

    expect($row->cow_milk_quantity)->toBe('14.000')
        ->and($row->buffalo_milk_quantity)->toBe('7.000');
});

test('the database refuses a duplicate farm date and shift even outside the action', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)->create();

    expect(fn () => MilkProduction::factory()->for($this->farm)->morning()->on($this->today)->create())
        ->toThrow(QueryException::class);
});

test('production has no milk_type column, because one row holds both types', function () {
    expect(Schema::hasColumn('milk_productions', 'milk_type'))->toBeFalse()
        ->and(Schema::hasColumn('milk_productions', 'cow_milk_quantity'))->toBeTrue()
        ->and(Schema::hasColumn('milk_productions', 'buffalo_milk_quantity'))->toBeTrue();
});

test('negative production is rejected', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->today,
        'shifts' => ['morning' => ['cow' => '-1.000', 'buffalo' => '2.000']],
    ])->assertSessionHasErrors('shifts.morning.cow');

    expect(MilkProduction::query()->count())->toBe(0);
});

test('a fourth decimal place is rejected rather than silently rounded', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->today,
        'shifts' => ['morning' => ['cow' => '1.2345', 'buffalo' => '0']],
    ])->assertSessionHasErrors('shifts.morning.cow');

    expect(MilkProduction::query()->count())->toBe(0);
});

test('there is no route for deleting production', function () {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter();

    expect($names)->not->toContain('milk.production.destroy');
});

/*
|--------------------------------------------------------------------------
| Internal usage
|--------------------------------------------------------------------------
*/

test('usage can be recorded within the available milk', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)
        ->quantities('10.000', '0.000')->create();

    $this->actingAs($this->admin)->post(route('milk.usage.store'), [
        'usage_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => MilkUsageType::CalfFeeding->value,
        'quantity' => '3.000',
    ])->assertRedirect();

    expect(MilkUsage::query()->count())->toBe(1);
});

test('usage beyond the available milk is refused and nothing is written', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->today)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('2.000')->create();

    // 2.000 already used, so 8.001 is one millilitre too many.
    $this->actingAs($this->admin)->post(route('milk.usage.store'), [
        'usage_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => MilkUsageType::HomeUse->value,
        'quantity' => '8.001',
    ])->assertSessionHasErrors('quantity');

    expect(MilkUsage::query()->count())->toBe(1);
});

test('usage is refused when production has not been entered at all', function () {
    // No production row: not zero milk, but no statement about the shift.
    $this->actingAs($this->admin)->post(route('milk.usage.store'), [
        'usage_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => MilkUsageType::CalfFeeding->value,
        'quantity' => '1.000',
    ])->assertSessionHasErrors('quantity');

    expect(MilkUsage::query()->count())->toBe(0);
});

test('cancelling usage keeps the row and needs a reason', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)
        ->quantities('10.000', '0.000')->create();

    $usage = MilkUsage::factory()->for($this->farm)->on($this->today)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('2.000')->create();

    $this->actingAs($this->admin)
        ->put(route('milk.usage.cancel', $usage), ['cancellation_reason' => ''])
        ->assertSessionHasErrors('cancellation_reason');

    expect($usage->fresh()->isCancelled())->toBeFalse();

    $this->actingAs($this->admin)
        ->put(route('milk.usage.cancel', $usage), ['cancellation_reason' => 'Recorded against the wrong shift'])
        ->assertRedirect();

    expect($usage->fresh()->isCancelled())->toBeTrue()
        // Nothing is deleted.
        ->and(MilkUsage::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Adjustments
|--------------------------------------------------------------------------
*/

test('an adjustment requires a reason', function () {
    $this->actingAs($this->admin)->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '1.000',
        'reason' => '',
    ])->assertSessionHasErrors('reason');

    expect(MilkAdjustment::query()->count())->toBe(0);
});

test('an authorised increase is recorded with its reason and direction', function () {
    $this->actingAs($this->admin)->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '0.500',
        'reason' => 'Collection point measured more than the shed record',
    ])->assertRedirect();

    $adjustment = MilkAdjustment::query()->firstOrFail();

    expect($adjustment->direction)->toBe(AdjustmentDirection::Increase)
        // Positive quantity; the direction carries the sign.
        ->and($adjustment->quantity)->toBe('0.500')
        ->and($adjustment->signedQuantity())->toBe('0.500')
        ->and($adjustment->reason)->not->toBe('');
});

test('a decrease stores a positive quantity and a negative effect', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->today)
        ->quantities('10.000', '0.000')->create();

    $this->actingAs($this->admin)->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Decrease->value,
        'quantity' => '1.500',
        'reason' => 'Spilled during transfer, recorded production was optimistic',
    ])->assertRedirect();

    $adjustment = MilkAdjustment::query()->firstOrFail();

    expect($adjustment->quantity)->toBe('1.500')
        ->and($adjustment->signedQuantity())->toBe('-1.500');
});

test('recording an adjustment is refused without the permission', function () {
    $user = userWithPermissions(['milk.production.view', 'milk.reconciliation.view']);

    $this->actingAs($user)->get(route('milk.adjustments.index'))->assertForbidden();

    $this->actingAs($user)->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '1.000',
        'reason' => 'Trying it on without authorisation',
    ])->assertForbidden();

    expect(MilkAdjustment::query()->count())->toBe(0);
});

test('recording usage is refused without the usage permission', function () {
    $user = userWithPermissions(['milk.usage.view']);

    $this->actingAs($user)->get(route('milk.usage.index'))->assertOk();

    $this->actingAs($user)->post(route('milk.usage.store'), [
        'usage_date' => $this->today,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => MilkUsageType::CalfFeeding->value,
        'quantity' => '1.000',
    ])->assertForbidden();

    expect(MilkUsage::query()->count())->toBe(0);
});

test('a milk page is refused without its permission', function (string $routeName, string $permission) {
    $without = userWithPermissions(['dashboard.view']);
    $this->actingAs($without)->get(route($routeName))->assertForbidden();

    $with = userWithPermissions([$permission]);
    $this->actingAs($with)->get(route($routeName))->assertOk();
})->with([
    'production' => ['milk.production.index', 'milk.production.view'],
    'usage' => ['milk.usage.index', 'milk.usage.view'],
    'adjustments' => ['milk.adjustments.index', 'milk.adjustment.create'],
    'reconciliation' => ['milk.reconciliation', 'milk.reconciliation.view'],
]);
