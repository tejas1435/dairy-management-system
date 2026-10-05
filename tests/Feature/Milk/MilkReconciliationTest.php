<?php

use App\Contracts\MilkSalesAllocator;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Models\Farm;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Services\Milk\NoMilkSalesRecorded;
use App\Services\Milk\RecordedMilkSales;
use App\Support\Milk\MilkReconciliation;
use App\Support\Milk\SalesAllocation;
use App\Support\Quantity;
use Illuminate\Support\Facades\Schema;

/*
 * The reconciliation engine.
 *
 *     available = production + authorised adjustments
 *     allocated = sales + internal usage
 *     remaining = available - allocated
 *
 * Per farm, date, shift and milk type. Two things get most of the attention here:
 * that nothing leaks between reconciliation units, and that a missing production
 * row never becomes a zero.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    $this->engine = app(CalculateMilkReconciliation::class);

    $this->at = fn (Shift $shift, MilkType $type, ?string $date = null) => $this->engine->forShift(
        $this->farm->id, $date ?? $this->date, $shift, $type
    );
});

/*
|--------------------------------------------------------------------------
| A. The formula
|--------------------------------------------------------------------------
*/

test('available is production plus net adjustments', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('20.000', '0.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('3.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->decrease('1.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->production)->toBe('20.000')
        ->and($result->increaseTotal)->toBe('3.000')
        ->and($result->decreaseTotal)->toBe('1.000')
        ->and($result->adjustmentTotal)->toBe('2.000')
        ->and($result->available)->toBe('22.000');
});

test('allocated is sales plus internal usage', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('20.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)
        ->usageType(MilkUsageType::CalfFeeding)->quantity('4.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->usageTotal)->toBe('4.000')
        // No sales subsystem, so a true zero contributes nothing.
        ->and($result->sales->total)->toBe('0.000')
        ->and($result->allocated)->toBe('4.000');
});

test('remaining is available minus allocated', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('20.000', '0.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('2.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('5.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->available)->toBe('22.000')
        ->and($result->allocated)->toBe('5.000')
        ->and($result->remaining)->toBe('17.000')
        ->and(Quantity::sub($result->available, $result->allocated))->toBe($result->remaining);
});

test('an untouched shift reports zeroes and says it is untouched', function () {
    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->productionEntered)->toBeFalse()
        ->and($result->production)->toBe('0.000')
        ->and($result->adjustmentTotal)->toBe('0.000')
        ->and($result->available)->toBe('0.000')
        ->and($result->allocated)->toBe('0.000')
        ->and($result->remaining)->toBe('0.000')
        ->and($result->isUntouched())->toBeTrue();
});

test('the result carries its own coordinates', function () {
    $result = ($this->at)(Shift::Evening, MilkType::Buffalo);

    expect($result)->toBeInstanceOf(MilkReconciliation::class)
        ->and($result->farmId)->toBe($this->farm->id)
        ->and($result->date)->toBe($this->date)
        ->and($result->shift)->toBe(Shift::Evening)
        ->and($result->milkType)->toBe(MilkType::Buffalo);
});

/*
|--------------------------------------------------------------------------
| B. Missing production versus a recorded zero — the critical matrix
|--------------------------------------------------------------------------
*/

test('case A: no row at all means production was not entered', function () {
    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->productionEntered)->toBeFalse()
        // The quantity is an arithmetic zero, but it travels with the flag.
        ->and($result->production)->toBe('0.000');
});

test('case B: a row with zero cow milk means production WAS entered', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('0.000', '5.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->productionEntered)->toBeTrue()
        ->and($result->production)->toBe('0.000');
});

test('case C: the same row reports the buffalo quantity for buffalo', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('0.000', '5.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Buffalo);

    expect($result->productionEntered)->toBeTrue()
        ->and($result->production)->toBe('5.000');
});

test('case D: a recorded morning and a missing evening report differently', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '5.000')->create();

    expect(($this->at)(Shift::Morning, MilkType::Cow)->productionEntered)->toBeTrue()
        ->and(($this->at)(Shift::Evening, MilkType::Cow)->productionEntered)->toBeFalse();
});

test('case E: both milk types report entered when the shift row exists', function () {
    // This is the case a row-per-milk-type schema could not express, and the
    // reason production is one row per shift (D30).
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('0.000', '8.000')->create();

    expect(($this->at)(Shift::Morning, MilkType::Cow)->productionEntered)->toBeTrue()
        ->and(($this->at)(Shift::Morning, MilkType::Buffalo)->productionEntered)->toBeTrue();
});

test('entered-ness is never inferred from the quantity being zero', function () {
    $missing = ($this->at)(Shift::Morning, MilkType::Cow);

    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)->recordedZero()->create();

    $recordedZero = ($this->at)(Shift::Morning, MilkType::Cow);

    // Identical quantities, opposite meanings.
    expect($missing->production)->toBe($recordedZero->production)
        ->and($missing->productionEntered)->toBeFalse()
        ->and($recordedZero->productionEntered)->toBeTrue();
});

test('a shift with only an adjustment is not untouched, even without production', function () {
    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('1.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->productionEntered)->toBeFalse()
        ->and($result->isUntouched())->toBeFalse()
        ->and($result->available)->toBe('1.000');
});

/*
|--------------------------------------------------------------------------
| C. Cow and buffalo isolation on one production row
|--------------------------------------------------------------------------
*/

test('cow and buffalo reconcile independently from the same shift row', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('20.000', '8.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('5.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Buffalo)->quantity('2.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('1.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Buffalo)->decrease('0.500')->create();

    $cow = ($this->at)(Shift::Morning, MilkType::Cow);
    $buffalo = ($this->at)(Shift::Morning, MilkType::Buffalo);

    expect($cow->production)->toBe('20.000')
        ->and($cow->adjustmentTotal)->toBe('1.000')
        ->and($cow->available)->toBe('21.000')
        ->and($cow->usageTotal)->toBe('5.000')
        ->and($cow->remaining)->toBe('16.000');

    expect($buffalo->production)->toBe('8.000')
        ->and($buffalo->adjustmentTotal)->toBe('-0.500')
        ->and($buffalo->available)->toBe('7.500')
        ->and($buffalo->usageTotal)->toBe('2.000')
        ->and($buffalo->remaining)->toBe('5.500');
});

test('forShiftAllTypes returns one result per milk type', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('12.000', '6.000')->create();

    $results = $this->engine->forShiftAllTypes($this->farm->id, $this->date, Shift::Morning);

    expect(array_keys($results))->toBe(['cow', 'buffalo'])
        ->and($results['cow']->production)->toBe('12.000')
        ->and($results['buffalo']->production)->toBe('6.000');
});

/*
|--------------------------------------------------------------------------
| D. Morning and evening isolation
|--------------------------------------------------------------------------
*/

test('each shift reconciles from its own row and its own allocations', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)
        ->quantities('7.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('4.000')->create();
    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Evening)->milkType(MilkType::Cow)->quantity('2.500')->create();

    $morning = ($this->at)(Shift::Morning, MilkType::Cow);
    $evening = ($this->at)(Shift::Evening, MilkType::Cow);

    expect($morning->production)->toBe('10.000')
        ->and($morning->usageTotal)->toBe('4.000')
        ->and($morning->remaining)->toBe('6.000');

    expect($evening->production)->toBe('7.000')
        ->and($evening->usageTotal)->toBe('2.500')
        ->and($evening->remaining)->toBe('4.500');
});

test('an evening adjustment does not reach the morning totals', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Evening)->milkType(MilkType::Cow)->increase('5.000')->create();

    expect(($this->at)(Shift::Morning, MilkType::Cow)->adjustmentTotal)->toBe('0.000')
        ->and(($this->at)(Shift::Morning, MilkType::Cow)->available)->toBe('10.000')
        ->and(($this->at)(Shift::Evening, MilkType::Cow)->available)->toBe('15.000');
});

test('forDate returns every shift and milk type for one day', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '5.000')->create();

    $results = $this->engine->forDate($this->farm->id, $this->date);

    expect(array_keys($results))->toBe(['morning', 'evening'])
        ->and(array_keys($results['morning']))->toBe(['cow', 'buffalo'])
        ->and($results['morning']['cow']->productionEntered)->toBeTrue()
        ->and($results['evening']['cow']->productionEntered)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| E. Date isolation, on the business DATE column
|--------------------------------------------------------------------------
*/

test('records from adjacent dates do not leak into a day', function () {
    foreach (['2026-09-09', '2026-09-10', '2026-09-11'] as $date) {
        MilkProduction::factory()->for($this->farm)->morning()->on($date)
            ->quantities('10.000', '0.000')->create();

        MilkUsage::factory()->for($this->farm)->on($date)
            ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('1.000')->create();

        MilkAdjustment::factory()->for($this->farm)->on($date)
            ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('0.500')->create();
    }

    foreach (['2026-09-09', '2026-09-10', '2026-09-11'] as $date) {
        $result = ($this->at)(Shift::Morning, MilkType::Cow, $date);

        expect($result->production)->toBe('10.000')
            ->and($result->adjustmentTotal)->toBe('0.500')
            ->and($result->usageTotal)->toBe('1.000')
            ->and($result->remaining)->toBe('9.500');
    }
});

test('a date with no records reports nothing even when neighbours have plenty', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on('2026-09-09')
        ->quantities('50.000', '50.000')->create();
    MilkProduction::factory()->for($this->farm)->morning()->on('2026-09-11')
        ->quantities('50.000', '50.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow, '2026-09-10');

    expect($result->productionEntered)->toBeFalse()
        ->and($result->production)->toBe('0.000');
});

test('a farm never sees another farm records', function () {
    $other = Farm::factory()->for($this->business)->create(['code' => 'OTHER']);

    MilkProduction::factory()->for($other)->morning()->on($this->date)
        ->quantities('99.000', '99.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->productionEntered)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| F. Usage breakdown
|--------------------------------------------------------------------------
*/

test('the usage breakdown is exact for every type and sums to the total', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    $quantities = [
        MilkUsageType::CalfFeeding->value => '1.000',
        MilkUsageType::HomeUse->value => '0.500',
        MilkUsageType::Sample->value => '0.250',
        MilkUsageType::Wastage->value => '0.125',
        MilkUsageType::Other->value => '0.125',
    ];

    foreach ($quantities as $type => $quantity) {
        MilkUsage::factory()->for($this->farm)->on($this->date)
            ->shift(Shift::Morning)->milkType(MilkType::Cow)
            ->usageType(MilkUsageType::from($type))->quantity($quantity)->create();
    }

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->usageTotal)->toBe('2.000')
        ->and($result->remaining)->toBe('8.000');

    foreach ($quantities as $type => $quantity) {
        expect($result->usageFor(MilkUsageType::from($type)))->toBe($quantity);
    }
});

test('every usage type is present in the breakdown, including the empty ones', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    // A stable set of keys, so the screen renders a stable set of rows.
    expect(array_keys($result->usageByType))->toBe(MilkUsageType::values());

    foreach (MilkUsageType::cases() as $type) {
        expect($result->usageFor($type))->toBe('0.000')
            ->and($result->hasUsageOf($type))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| G. Cancelled records are excluded
|--------------------------------------------------------------------------
*/

test('cancelled usage is excluded from allocation', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('3.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('4.000')->cancelled()->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->usageTotal)->toBe('3.000')
        ->and($result->remaining)->toBe('7.000');
});

test('cancelled adjustments are excluded from availability', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('2.000')->create();

    MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('5.000')->cancelled()->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->adjustmentTotal)->toBe('2.000')
        ->and($result->available)->toBe('12.000');
});

/*
|--------------------------------------------------------------------------
| H. Over-allocation reporting
|--------------------------------------------------------------------------
*/

test('an over-allocated shift is reported as such', function () {
    // Constructed directly: the normal workflows refuse to create this state.
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('10.001')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->remaining)->toBe('-0.001')
        ->and($result->isOverAllocated())->toBeTrue()
        ->and($result->isFullyAllocated())->toBeFalse()
        ->and($result->remainingBadge())->toContain('danger');
});

test('wouldOverAllocate predicts the boundary exactly', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->wouldOverAllocate('10.000'))->toBeFalse()
        ->and($result->wouldOverAllocate('10.001'))->toBeTrue();
});

test('a fully allocated shift is marked as success, not as a problem', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('10.000')->create();

    $result = ($this->at)(Shift::Morning, MilkType::Cow);

    expect($result->isFullyAllocated())->toBeTrue()
        ->and($result->remainingBadge())->toContain('success');
});

/*
|--------------------------------------------------------------------------
| I. The sales allocator boundary
|--------------------------------------------------------------------------
*/

test('the real allocator is bound now that milk_sales exists', function () {
    /*
     * Phase 3 bound NoMilkSalesRecorded and asserted here that no sales subsystem
     * existed. Phase 4 created `milk_sales` and bound the real allocator, so that
     * assertion has been inverted rather than deleted: the seam still has to be
     * exactly one binding, and this is what proves it is.
     */
    $allocator = app(MilkSalesAllocator::class);

    expect($allocator)->toBeInstanceOf(RecordedMilkSales::class);

    $allocation = $allocator->allocationFor($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($allocation)->toBeInstanceOf(SalesAllocation::class)
        // No sales on this date, so a genuine zero — but the subsystem exists, which
        // is a different statement from Phase 3's.
        ->and($allocation->total)->toBe('0.000')
        ->and($allocation->subsystemExists)->toBeTrue();
});

test('the allocator reports the seeded channels, so the breakdown is data-driven', function () {
    seedPhase2Masters();

    $allocator = app(MilkSalesAllocator::class);
    $allocation = $allocator->allocationFor($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    // Now that sales can exist, a channel with none is a real observation rather
    // than a missing feature, so it appears as zero.
    expect($allocator->channels())->toContain('mandali', 'vendor', 'direct_customer');

    foreach (['mandali', 'vendor', 'direct_customer'] as $slug) {
        expect($allocation->forChannel($slug))->toBe('0.000');
    }
});

test('the milk_sales table exists and is generic across channels', function () {
    expect(Schema::hasTable('milk_sales'))->toBeTrue();

    // Generic: keyed by channel and buyer, with nothing customer-specific on it.
    foreach (['sales_channel_id', 'buyer_id', 'source', 'unit_rate', 'amount'] as $column) {
        expect(Schema::hasColumn('milk_sales', $column))->toBeTrue();
    }

    expect(Schema::hasColumn('milk_sales', 'customer_id'))->toBeFalse();
});

test('the retained null allocator can still be bound explicitly', function () {
    // NoMilkSalesRecorded is no longer the default binding, but it remains the only
    // implementation of the "no subsystem" state, and a test that wants
    // reconciliation without its sales half can ask for it.
    app()->bind(MilkSalesAllocator::class, NoMilkSalesRecorded::class);

    $allocation = app(MilkSalesAllocator::class)
        ->allocationFor($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($allocation->subsystemExists)->toBeFalse()
        ->and($allocation->byChannel)->toBe([]);
});

test('a replacement allocator feeds the engine without the engine changing', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('20.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('2.000')->create();

    /*
     * Stands in for what Phase 4 binds. If the seam works, allocated picks the
     * sales up and remaining falls, with no change to CalculateMilkReconciliation,
     * MilkReconciliation or the view.
     */
    app()->bind(MilkSalesAllocator::class, fn (): MilkSalesAllocator => new class implements MilkSalesAllocator
    {
        public function allocationFor(int $farmId, string $date, Shift $shift, MilkType $milkType): SalesAllocation
        {
            return SalesAllocation::fromChannels([
                'mandali' => '5.000',
                'direct_customer' => '3.000',
            ]);
        }

        public function channels(): array
        {
            return ['mandali', 'direct_customer'];
        }
    });

    $result = app(CalculateMilkReconciliation::class)
        ->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->subsystemExists)->toBeTrue()
        ->and($result->sales->total)->toBe('8.000')
        ->and($result->sales->forChannel('mandali'))->toBe('5.000')
        ->and($result->sales->forChannel('direct_customer'))->toBe('3.000')
        ->and($result->sales->forChannel('vendor'))->toBe('0.000')
        // Sales and usage both count towards allocated.
        ->and($result->allocated)->toBe('10.000')
        ->and($result->remaining)->toBe('10.000');
});

test('SalesAllocation sums its channels exactly', function () {
    $allocation = SalesAllocation::fromChannels([
        'mandali' => '3.333',
        'vendor' => '3.333',
        'direct_customer' => '3.334',
    ]);

    expect($allocation->total)->toBe('10.000')
        ->and($allocation->subsystemExists)->toBeTrue()
        ->and($allocation->isEmpty())->toBeFalse();
});

test('an empty SalesAllocation from a real subsystem still reports it exists', function () {
    // Phase 4 on a day with no sales: the subsystem exists and reported zero,
    // which is a different statement from Phase 3's "no subsystem".
    $allocation = SalesAllocation::fromChannels([]);

    expect($allocation->subsystemExists)->toBeTrue()
        ->and($allocation->total)->toBe('0.000')
        ->and($allocation->isEmpty())->toBeTrue();
});
