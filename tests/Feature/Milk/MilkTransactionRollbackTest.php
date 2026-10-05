<?php

use App\Actions\Milk\CancelMilkAdjustment;
use App\Actions\Milk\CancelMilkUsage;
use App\Actions\Milk\RecordMilkAdjustment;
use App\Actions\Milk\RecordMilkUsage;
use App\Actions\Milk\SaveMilkProduction;
use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Services\Milk\CalculateMilkReconciliation;

/*
 * These prove the Phase 3 transactions are real, not decorative.
 *
 * Failures are injected with Eloquent model events registered inside the test.
 * Nothing in production code knows about them, so there is no test-only failure
 * switch shipped to users.
 *
 * The property under test is the same each time: a write that touches more than one
 * table either happens completely or not at all. A production row without its audit
 * entry, or a cancelled usage whose cancellation was never recorded, is worse than
 * a failed save, because the failure is invisible.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    $this->actingAs(superAdmin());
});

/** Makes the next insert of the given model throw. */
function failOnCreatingMilk(string $modelClass): void
{
    $modelClass::creating(function (): never {
        throw new RuntimeException('Injected failure');
    });
}

/** Makes the next update of the given model throw. */
function failOnUpdatingMilk(string $modelClass): void
{
    $modelClass::updating(function (): never {
        throw new RuntimeException('Injected failure');
    });
}

afterEach(function (): void {
    // Model event listeners are static, so they must not leak into other tests.
    AuditLog::flushEventListeners();
    MilkProduction::flushEventListeners();
    MilkUsage::flushEventListeners();
    MilkAdjustment::flushEventListeners();

    // Re-register the guards the models rely on.
    AuditLog::bootTraits();
});

/*
|--------------------------------------------------------------------------
| A. Production
|--------------------------------------------------------------------------
*/

test('a production save rolls back completely when the audit write fails', function () {
    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(SaveMilkProduction::class)
        ->forShift($this->date, Shift::Morning, '10.000', '5.000'))
        ->toThrow(RuntimeException::class);

    expect(MilkProduction::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_production')->count())->toBe(0);
});

test('a whole-day save rolls back the morning when the evening fails', function () {
    /*
     * forDay() is defined as one transaction, so a day cannot end up with its
     * morning saved and its evening lost. The failure is injected on the second
     * insert, which is the evening.
     */
    $inserts = 0;

    MilkProduction::creating(function () use (&$inserts): void {
        $inserts++;

        if ($inserts === 2) {
            throw new RuntimeException('Injected failure on the evening shift');
        }
    });

    expect(fn () => app(SaveMilkProduction::class)->forDay($this->date, [
        'morning' => ['cow' => '10.000', 'buffalo' => '5.000'],
        'evening' => ['cow' => '9.000', 'buffalo' => '4.000'],
    ]))->toThrow(RuntimeException::class);

    // Neither shift survives, and no audit record claims one was saved.
    expect(MilkProduction::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_production')->count())->toBe(0);
});

test('a correction rolls back and leaves the earlier figures intact', function () {
    app(SaveMilkProduction::class)->forShift($this->date, Shift::Morning, '10.000', '5.000');

    $auditCount = AuditLog::query()->where('auditable_type', 'milk_production')->count();

    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(SaveMilkProduction::class)
        ->forShift($this->date, Shift::Morning, '11.000', '6.000'))
        ->toThrow(RuntimeException::class);

    $row = MilkProduction::query()->firstOrFail();

    // The original values are still there, not half-updated.
    expect($row->cow_milk_quantity)->toBe('10.000')
        ->and($row->buffalo_milk_quantity)->toBe('5.000')
        ->and(AuditLog::query()->where('auditable_type', 'milk_production')->count())->toBe($auditCount);
});

/*
|--------------------------------------------------------------------------
| B. Usage
|--------------------------------------------------------------------------
*/

test('recording usage rolls back completely when the audit write fails', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(RecordMilkUsage::class)->handle(
        $this->date, Shift::Morning, MilkType::Cow, MilkUsageType::CalfFeeding, '2.000'
    ))->toThrow(RuntimeException::class);

    expect(MilkUsage::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_usage')->count())->toBe(0);
});

test('cancelling usage rolls back and the usage stays active', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    $usage = MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('4.000')->create();

    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(CancelMilkUsage::class)->handle($usage, 'A reason that will not survive'))
        ->toThrow(RuntimeException::class);

    $usage->refresh();

    // Still counting towards allocation, because the cancellation never happened.
    expect($usage->status)->toBe(TransactionStatus::Active)
        ->and($usage->cancelled_at)->toBeNull()
        ->and($usage->cancellation_reason)->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', 'milk_usage')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. Adjustments
|--------------------------------------------------------------------------
*/

test('recording an adjustment rolls back completely when the audit write fails', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(RecordMilkAdjustment::class)->handle(
        $this->date, Shift::Morning, MilkType::Cow, AdjustmentDirection::Increase,
        '2.000', 'A measurement difference that will not survive'
    ))->toThrow(RuntimeException::class);

    // An adjustment without its audit entry is the one record that must never
    // exist: an unexplained change to available milk.
    expect(MilkAdjustment::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_adjustment')->count())->toBe(0);
});

test('cancelling an adjustment rolls back and it stays active', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    $adjustment = MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('2.000')->create();

    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(CancelMilkAdjustment::class)->handle($adjustment, 'A reason that will not survive'))
        ->toThrow(RuntimeException::class);

    $adjustment->refresh();

    expect($adjustment->status)->toBe(TransactionStatus::Active)
        ->and($adjustment->cancelled_at)->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', 'milk_adjustment')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D. A failed write leaves the reconciliation exactly as it was
|--------------------------------------------------------------------------
*/

test('a failed usage save leaves the remaining figure untouched', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('10.000', '0.000')->create();

    MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('3.000')->create();

    $engine = app(CalculateMilkReconciliation::class);
    $before = $engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    failOnCreatingMilk(AuditLog::class);

    expect(fn () => app(RecordMilkUsage::class)->handle(
        $this->date, Shift::Morning, MilkType::Cow, MilkUsageType::HomeUse, '2.000'
    ))->toThrow(RuntimeException::class);

    $after = $engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($after->usageTotal)->toBe($before->usageTotal)
        ->and($after->remaining)->toBe($before->remaining)
        ->and($after->remaining)->toBe('7.000');
});

test('a failed production update leaves the reconciliation on the old figures', function () {
    app(SaveMilkProduction::class)->forShift($this->date, Shift::Morning, '10.000', '0.000');

    failOnUpdatingMilk(MilkProduction::class);

    expect(fn () => app(SaveMilkProduction::class)
        ->forShift($this->date, Shift::Morning, '99.000', '0.000'))
        ->toThrow(RuntimeException::class);

    $result = app(CalculateMilkReconciliation::class)
        ->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->production)->toBe('10.000')
        ->and($result->available)->toBe('10.000');
});
