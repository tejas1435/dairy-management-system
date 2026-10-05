<?php

use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Services\Milk\CalculateMilkReconciliation;
use Illuminate\Support\Facades\DB;

/*
 * Query-count guards for the Phase 3 screens.
 *
 * Each asserts exact equality between a page showing one related record and the
 * same page showing many, with the per-request caches warmed first. Comparing
 * against an empty page would measure Eloquent skipping eager loads on an empty
 * collection rather than a query per row, which is the mistake these guards were
 * rewritten to avoid in Phase 2.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    // Plenty of production, so nothing is refused for lack of milk.
    foreach (Shift::cases() as $shift) {
        MilkProduction::factory()->for($this->farm)->shift($shift)->on($this->date)
            ->quantities('1000.000', '1000.000')->create();
    }

    $this->actingAs($this->admin);
});

/** Counts the queries a callback runs. */
function countMilkQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** One unmeasured request, so per-request caches are already loaded. */
function warmMilkCaches(string $url): void
{
    test()->get($url)->assertOk();
}

/** Records `$count` usages spread across the usage types. */
function seedUsages(object $test, int $count): void
{
    $types = MilkUsageType::cases();

    foreach (range(1, $count) as $i) {
        MilkUsage::factory()->for($test->farm)->on($test->date)
            ->shift($i % 2 === 0 ? Shift::Morning : Shift::Evening)
            ->milkType($i % 3 === 0 ? MilkType::Buffalo : MilkType::Cow)
            ->usageType($types[$i % count($types)])
            ->quantity('0.500')
            ->create();
    }
}

test('the usage screen costs the same however many usages it lists', function () {
    seedUsages($this, 1);

    $url = route('milk.usage.index', ['date' => $this->date]);
    warmMilkCaches($url);

    $withOne = countMilkQueries(fn () => $this->get($url)->assertOk());

    seedUsages($this, 14);

    $withFifteen = countMilkQueries(fn () => $this->get($url)->assertOk());

    expect($withFifteen)->toBe($withOne);
});

test('the adjustment screen costs the same however many adjustments it lists', function () {
    MilkAdjustment::factory()->for($this->farm)->on($this->date)->increase('0.100')->create();

    $url = route('milk.adjustments.index', ['date' => $this->date]);
    warmMilkCaches($url);

    $withOne = countMilkQueries(fn () => $this->get($url)->assertOk());

    foreach (range(1, 12) as $i) {
        MilkAdjustment::factory()->for($this->farm)->on($this->date)
            ->shift($i % 2 === 0 ? Shift::Morning : Shift::Evening)
            ->milkType($i % 3 === 0 ? MilkType::Buffalo : MilkType::Cow)
            ->increase('0.100')->create();
    }

    $withThirteen = countMilkQueries(fn () => $this->get($url)->assertOk());

    expect($withThirteen)->toBe($withOne);
});

test('the reconciliation screen costs the same however much milk moved', function () {
    seedUsages($this, 1);
    MilkAdjustment::factory()->for($this->farm)->on($this->date)->increase('0.100')->create();

    $url = route('milk.reconciliation', ['date' => $this->date]);
    warmMilkCaches($url);

    $sparse = countMilkQueries(fn () => $this->get($url)->assertOk());

    seedUsages($this, 20);

    foreach (range(1, 10) as $i) {
        MilkAdjustment::factory()->for($this->farm)->on($this->date)
            ->shift($i % 2 === 0 ? Shift::Morning : Shift::Evening)
            ->increase('0.100')->create();
    }

    $busy = countMilkQueries(fn () => $this->get($url)->assertOk());

    // The engine aggregates in SQL, so more rows is more work for MySQL and the
    // same number of round trips.
    expect($busy)->toBe($sparse);
});

test('the production matrix costs the same whether one shift is entered or both', function () {
    // The matrix reads at most one row per shift, so this is really a guard
    // against a future change that loads per milk type instead.
    $url = route('milk.production.index', ['date' => $this->date]);
    warmMilkCaches($url);

    $bothShifts = countMilkQueries(fn () => $this->get($url)->assertOk());

    $emptyDate = route('milk.production.index', ['date' => '2026-09-20']);
    warmMilkCaches($emptyDate);

    $noShifts = countMilkQueries(fn () => $this->get($emptyDate)->assertOk());

    // Two rows with their creator and updater eager-loaded cost two more queries
    // than no rows at all: the eager loads do not fire on an empty result.
    expect($bothShifts - $noShifts)->toBeLessThanOrEqual(2);
});

test('one reconciliation unit is a fixed small number of queries', function () {
    seedUsages($this, 20);

    $engine = app(CalculateMilkReconciliation::class);

    // One unmeasured call, so the allocator's memoised channel list is already
    // loaded and the count reflects the steady state.
    $engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    $count = countMilkQueries(fn () => $engine->forShift(
        $this->farm->id, $this->date, Shift::Morning, MilkType::Cow
    ));

    /*
     * Production, adjustments grouped by direction, usage grouped by type, and sales
     * grouped by channel — four, plus nothing for the channel list, which the
     * allocator memoises on its singleton and which the warm-up above has already
     * fetched.
     *
     * Phase 3 asserted 3 here, when the bound allocator issued no query because no
     * sales subsystem existed. Phase 4 bound the real one (docs/DECISIONS.md D32), so
     * the figure is 4 — still fixed, and still independent of how much was sold.
     */
    expect($count)->toBe(4);
});

test('a whole day of reconciliation does not grow with the number of records', function () {
    $engine = app(CalculateMilkReconciliation::class);

    /*
     * The allocator memoises its channel list per instance, and it is bound as a
     * singleton, so the very first reconciliation of a request pays one extra query
     * for it. Warming here measures the steady state rather than that one-off — the
     * same reason the page-level guards above warm the request caches first.
     */
    $engine->forDate($this->farm->id, $this->date);

    seedUsages($this, 2);
    $sparse = countMilkQueries(fn () => $engine->forDate($this->farm->id, $this->date));

    seedUsages($this, 30);
    $busy = countMilkQueries(fn () => $engine->forDate($this->farm->id, $this->date));

    expect($busy)->toBe($sparse);
});
