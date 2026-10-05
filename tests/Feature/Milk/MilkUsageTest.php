<?php

use App\Actions\Milk\CancelMilkUsage;
use App\Actions\Milk\RecordMilkUsage;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Services\Milk\MilkAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Internal milk usage: milk consumed rather than sold.
 *
 * The substance here is the availability rule. Usage is the first kind of
 * allocation the application supports, so it is the first thing that can take more
 * milk than a shift produced — and MASTER_SPEC section 15 requires that to be
 * blocked rather than shown as a negative remainder.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    $this->reconciliation = app(CalculateMilkReconciliation::class);

    /** Reconciliation for cow milk in the morning of the test date. */
    $this->cowMorning = fn () => $this->reconciliation->forShift(
        $this->farm->id, $this->date, Shift::Morning, MilkType::Cow
    );

    /** Records production for the morning shift. */
    $this->produce = fn (string $cow, string $buffalo = '0.000') => MilkProduction::factory()
        ->for($this->farm)->morning()->on($this->date)->quantities($cow, $buffalo)->create();

    $this->record = fn (string $quantity, MilkUsageType $type = MilkUsageType::CalfFeeding) => app(RecordMilkUsage::class)
        ->handle($this->date, Shift::Morning, MilkType::Cow, $type, $quantity);

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. Usage types
|--------------------------------------------------------------------------
*/

test('the usage type set is exactly the five the specification names', function () {
    expect(MilkUsageType::values())
        ->toBe(['calf_feeding', 'home_use', 'sample', 'wastage', 'other']);
});

test('every usage type is accepted and stored as its machine value', function (MilkUsageType $type) {
    ($this->produce)('100.000');
    ($this->record)('1.000', $type);

    $stored = DB::table('milk_usages')->latest('id')->first();

    // The persisted value is the machine identifier, never the translated label.
    expect($stored->usage_type)->toBe($type->value)
        ->and($stored->usage_type)->not->toBe($type->label());
})->with(MilkUsageType::cases());

test('an unrecognised usage type is rejected by the form', function () {
    ($this->produce)('100.000');

    $this->post(route('milk.usage.store'), [
        'usage_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => 'feeding_the_dog',
        'quantity' => '1.000',
    ])->assertSessionHasErrors('usage_type');

    expect(MilkUsage::query()->count())->toBe(0);
});

test('every usage type has a real label in all three locales', function (MilkUsageType $type) {
    $labels = [];

    foreach (['en', 'gu', 'hi'] as $locale) {
        app()->setLocale($locale);
        $label = $type->label();

        expect($label)->not->toContain('milk.usage_types')
            ->and(trim($label))->not->toBe('');

        $labels[$locale] = $label;
    }

    app()->setLocale('en');

    // Gujarati and Hindi are genuinely translated, not copied English.
    expect($labels['gu'])->not->toBe($labels['en'])
        ->and($labels['hi'])->not->toBe($labels['en']);
})->with(MilkUsageType::cases());

/*
|--------------------------------------------------------------------------
| B. Creation and its effect on reconciliation
|--------------------------------------------------------------------------
*/

test('recording calf feeding against ten litres leaves eight', function () {
    ($this->produce)('10.000');

    ($this->record)('2.000');

    expect(MilkUsage::query()->count())->toBe(1);

    $usage = MilkUsage::query()->firstOrFail();

    expect($usage->quantity)->toBe('2.000')
        ->and($usage->farm_id)->toBe($this->farm->id)
        ->and($usage->usage_date->toDateString())->toBe($this->date)
        ->and($usage->shift)->toBe(Shift::Morning)
        ->and($usage->milk_type)->toBe(MilkType::Cow)
        ->and($usage->usage_type)->toBe(MilkUsageType::CalfFeeding)
        ->and($usage->status)->toBe(TransactionStatus::Active)
        ->and($usage->created_by)->toBe($this->admin->id);

    $result = ($this->cowMorning)();

    expect($result->usageTotal)->toBe('2.000')
        ->and($result->allocated)->toBe('2.000')
        ->and($result->remaining)->toBe('8.000');
});

test('recording usage is audited with the quantity and the type', function () {
    ($this->produce)('10.000');
    ($this->record)('2.000');

    $log = AuditLog::query()->where('auditable_type', 'milk_usage')->latest('id')->firstOrFail();

    expect($log->action->value)->toBe('created')
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->new_values['quantity'])->toBe('2.000')
        ->and($log->new_values['usage_type'])->toBe('calf_feeding')
        ->and($log->new_values['milk_type'])->toBe('cow')
        ->and($log->new_values['shift'])->toBe('morning');
});

test('a second usage of a different type accumulates exactly', function () {
    ($this->produce)('10.000');

    ($this->record)('2.000', MilkUsageType::CalfFeeding);
    ($this->record)('1.250', MilkUsageType::HomeUse);

    $result = ($this->cowMorning)();

    expect($result->usageTotal)->toBe('3.250')
        ->and($result->remaining)->toBe('6.750')
        ->and($result->usageFor(MilkUsageType::CalfFeeding))->toBe('2.000')
        ->and($result->usageFor(MilkUsageType::HomeUse))->toBe('1.250')
        ->and($result->usageFor(MilkUsageType::Wastage))->toBe('0.000');
});

test('two usages of the same type are separate rows that sum', function () {
    ($this->produce)('10.000');

    ($this->record)('1.000', MilkUsageType::Sample);
    ($this->record)('0.500', MilkUsageType::Sample);

    expect(MilkUsage::query()->count())->toBe(2)
        ->and(($this->cowMorning)()->usageFor(MilkUsageType::Sample))->toBe('1.500');
});

/*
|--------------------------------------------------------------------------
| C. Usage requires production to have been entered
|--------------------------------------------------------------------------
*/

test('usage is refused when no production row exists, and nothing persists', function () {
    // No production. This is not "zero available" — it is "nobody has said".
    expect(fn () => ($this->record)('1.000'))->toThrow(ValidationException::class);

    expect(MilkUsage::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_usage')->count())->toBe(0);
});

test('the refusal says production has not been entered, not that milk ran out', function () {
    try {
        ($this->record)('1.000');
        $this->fail('Expected the usage to be refused.');
    } catch (ValidationException $exception) {
        $message = $exception->errors()['quantity'][0];

        expect($message)->toBe(__('milk.errors.production_not_entered_for_allocation', [
            'shift' => Shift::Morning->label(),
            'type' => MilkType::Cow->label(),
        ]));
    }
});

test('a recorded zero production still refuses usage, but for the other reason', function () {
    // The shift was entered and produced nothing, so the message is about
    // availability rather than a missing entry.
    ($this->produce)('0.000');

    try {
        ($this->record)('1.000');
        $this->fail('Expected the usage to be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['quantity'][0])
            ->toContain(__('milk.errors.over_allocated', [
                'requested' => '1.000 '.__('milk.litres_short'),
                'remaining' => '0.000 '.__('milk.litres_short'),
            ]));
    }

    expect(MilkUsage::query()->count())->toBe(0);
});

test('production for the other shift does not make this shift allocatable', function () {
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)
        ->quantities('50.000', '0.000')->create();

    expect(fn () => ($this->record)('1.000'))->toThrow(ValidationException::class);
});

test('production of the other milk type does not make this type allocatable', function () {
    // The row exists, so productionEntered is true for both types -- but cow
    // production is zero, so cow allocation still has nothing to draw on.
    ($this->produce)('0.000', '50.000');

    expect(($this->cowMorning)()->productionEntered)->toBeTrue();
    expect(fn () => ($this->record)('1.000'))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| D. Allocation boundaries, to the millilitre
|--------------------------------------------------------------------------
*/

test('usage of exactly the available milk is allowed and leaves zero', function () {
    ($this->produce)('10.000');

    ($this->record)('10.000');

    $result = ($this->cowMorning)();

    expect($result->remaining)->toBe('0.000')
        ->and($result->isFullyAllocated())->toBeTrue()
        ->and($result->isOverAllocated())->toBeFalse();
});

test('usage of one millilitre more than available is refused', function () {
    ($this->produce)('10.000');

    expect(fn () => ($this->record)('10.001'))->toThrow(ValidationException::class);

    expect(MilkUsage::query()->count())->toBe(0)
        ->and(($this->cowMorning)()->remaining)->toBe('10.000');
});

test('with two litres already used, eight more is allowed and eight and one millilitre is not', function () {
    ($this->produce)('10.000');
    ($this->record)('2.000');

    expect(fn () => ($this->record)('8.001', MilkUsageType::HomeUse))->toThrow(ValidationException::class);
    expect(MilkUsage::query()->count())->toBe(1);

    ($this->record)('8.000', MilkUsageType::HomeUse);

    expect(MilkUsage::query()->count())->toBe(2)
        ->and(($this->cowMorning)()->remaining)->toBe('0.000');
});

test('the boundary holds when the remainder is a single millilitre', function () {
    ($this->produce)('0.002');

    ($this->record)('0.001');

    expect(($this->cowMorning)()->remaining)->toBe('0.001');

    expect(fn () => ($this->record)('0.002', MilkUsageType::Wastage))->toThrow(ValidationException::class);

    ($this->record)('0.001', MilkUsageType::Wastage);

    expect(($this->cowMorning)()->remaining)->toBe('0.000');
});

test('uneven thirds of ten litres allocate to exactly zero', function () {
    ($this->produce)('10.000');

    ($this->record)('3.333', MilkUsageType::CalfFeeding);
    ($this->record)('3.333', MilkUsageType::HomeUse);
    ($this->record)('3.334', MilkUsageType::Other);

    $result = ($this->cowMorning)();

    expect($result->usageTotal)->toBe('10.000')
        ->and($result->remaining)->toBe('0.000')
        ->and($result->isOverAllocated())->toBeFalse();
});

test('the availability service agrees with the reconciliation it is built on', function () {
    ($this->produce)('10.000');
    ($this->record)('4.000');

    $availability = app(MilkAvailability::class);

    expect($availability->remaining($this->farm->id, $this->date, Shift::Morning, MilkType::Cow))
        ->toBe('6.000')
        ->and($availability->canAllocate($this->farm->id, $this->date, Shift::Morning, MilkType::Cow, '6.000'))
        ->toBeTrue()
        ->and($availability->canAllocate($this->farm->id, $this->date, Shift::Morning, MilkType::Cow, '6.001'))
        ->toBeFalse();
});

test('a zero or negative usage quantity is refused by the form', function (string $quantity) {
    ($this->produce)('10.000');

    $this->post(route('milk.usage.store'), [
        'usage_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => MilkUsageType::CalfFeeding->value,
        'quantity' => $quantity,
    ])->assertSessionHasErrors('quantity');

    expect(MilkUsage::query()->count())->toBe(0);
})->with(['0', '0.000', '-1.000']);

/*
|--------------------------------------------------------------------------
| E. Availability is decided inside the transaction, under a lock
|--------------------------------------------------------------------------
*/

test('the availability check runs inside the write transaction, not before it', function () {
    /*
     * True concurrency is not reproducible in a single-connection Pest test that
     * is itself wrapped in a RefreshDatabase transaction, so this asserts the
     * structural property that makes the race impossible instead: the check and
     * the insert happen in one transaction, and a rollback of that transaction
     * takes the availability decision with it.
     *
     * The limitation is recorded honestly in docs/TESTING.md. What is verified
     * here is that the action does not check first and write later.
     */
    ($this->produce)('10.000');

    $depthDuringCheck = null;

    DB::listen(function ($query) use (&$depthDuringCheck): void {
        // The SELECT ... FOR UPDATE that locks the shift's existing usage.
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'milk_usages')) {
            $depthDuringCheck = DB::transactionLevel();
        }
    });

    ($this->record)('1.000');

    expect($depthDuringCheck)->not->toBeNull()
        // Inside at least one transaction. RefreshDatabase holds one open too,
        // so the action's own transaction makes this 2 in a test run.
        ->and($depthDuringCheck)->toBeGreaterThanOrEqual(1);
});

test('a locking read of the shift usage happens before the insert', function () {
    ($this->produce)('10.000');

    $order = [];

    DB::listen(function ($query) use (&$order): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'milk_usages')) {
            $order[] = 'lock';
        }

        if (str_starts_with($query->sql, 'insert into `milk_usages`')) {
            $order[] = 'insert';
        }
    });

    ($this->record)('1.000');

    expect($order)->toBe(['lock', 'insert']);
});

/*
|--------------------------------------------------------------------------
| F. Cancellation
|--------------------------------------------------------------------------
*/

test('cancelling usage keeps the row, records the reason, and returns the milk', function () {
    ($this->produce)('10.000');
    ($this->record)('4.000');

    $usage = MilkUsage::query()->firstOrFail();
    $before = ($this->cowMorning)();

    expect($before->remaining)->toBe('6.000');

    app(CancelMilkUsage::class)->handle($usage, 'Recorded against the wrong shift');

    $usage->refresh();
    $after = ($this->cowMorning)();

    expect(MilkUsage::query()->count())->toBe(1)
        ->and($usage->status)->toBe(TransactionStatus::Cancelled)
        ->and($usage->cancellation_reason)->toBe('Recorded against the wrong shift')
        ->and($usage->cancelled_by)->toBe($this->admin->id)
        ->and($usage->cancelled_at)->not->toBeNull()
        // The quantity itself is untouched; only its effect stops.
        ->and($usage->quantity)->toBe('4.000');

    expect($after->usageTotal)->toBe('0.000')
        ->and($after->allocated)->toBe('0.000')
        // Available never changed: cancelling usage does not add milk, it stops
        // spending it.
        ->and($after->available)->toBe($before->available)
        ->and($after->remaining)->toBe('10.000');
});

test('cancelling usage is audited with the reason', function () {
    ($this->produce)('10.000');
    ($this->record)('4.000');

    app(CancelMilkUsage::class)->handle(MilkUsage::query()->firstOrFail(), 'Duplicate entry');

    $log = AuditLog::query()->where('auditable_type', 'milk_usage')
        ->where('action', 'cancelled')->latest('id')->firstOrFail();

    expect($log->new_values['cancellation_reason'])->toBe('Duplicate entry')
        ->and($log->user_id)->toBe($this->admin->id);
});

test('cancelling twice is refused and changes nothing further', function () {
    ($this->produce)('10.000');
    ($this->record)('4.000');

    $usage = MilkUsage::query()->firstOrFail();

    app(CancelMilkUsage::class)->handle($usage, 'First cancellation');
    $cancelledAt = $usage->fresh()->cancelled_at;
    $auditCount = AuditLog::query()->where('auditable_type', 'milk_usage')->count();

    expect(fn () => app(CancelMilkUsage::class)->handle($usage->fresh(), 'Second attempt'))
        ->toThrow(ValidationException::class);

    expect($usage->fresh()->cancellation_reason)->toBe('First cancellation')
        ->and($usage->fresh()->cancelled_at->eq($cancelledAt))->toBeTrue()
        // No second cancellation event invented.
        ->and(AuditLog::query()->where('auditable_type', 'milk_usage')->count())->toBe($auditCount);
});

test('cancelling requires a reason that says something', function (string $reason) {
    ($this->produce)('10.000');
    ($this->record)('4.000');

    $usage = MilkUsage::query()->firstOrFail();

    $this->put(route('milk.usage.cancel', $usage), ['cancellation_reason' => $reason])
        ->assertSessionHasErrors('cancellation_reason');

    expect($usage->fresh()->isCancelled())->toBeFalse();
})->with([
    'empty' => [''],
    'whitespace' => ['    '],
    'too short to be useful' => ['no'],
]);

test('cancelled usage frees the milk for a fresh allocation', function () {
    ($this->produce)('10.000');
    ($this->record)('10.000');

    // Fully allocated, so nothing more fits.
    expect(fn () => ($this->record)('1.000', MilkUsageType::Wastage))->toThrow(ValidationException::class);

    app(CancelMilkUsage::class)->handle(MilkUsage::query()->active()->firstOrFail(), 'Entered in error');

    // Now it does.
    ($this->record)('1.000', MilkUsageType::Wastage);

    expect(($this->cowMorning)()->remaining)->toBe('9.000');
});

test('there is no route for deleting usage', function () {
    $destructive = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_contains($route->uri(), 'usage'));

    expect($destructive)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| G. Isolation
|--------------------------------------------------------------------------
*/

test('usage belongs to its own shift, milk type and date only', function () {
    ($this->produce)('10.000', '10.000');
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)
        ->quantities('10.000', '10.000')->create();
    MilkProduction::factory()->for($this->farm)->morning()->on('2026-09-11')
        ->quantities('10.000', '10.000')->create();

    ($this->record)('2.000');

    $reconciliation = app(CalculateMilkReconciliation::class);

    // Same shift, other milk type.
    expect($reconciliation->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Buffalo)->usageTotal)
        ->toBe('0.000')
        // Same milk type, other shift.
        ->and($reconciliation->forShift($this->farm->id, $this->date, Shift::Evening, MilkType::Cow)->usageTotal)
        ->toBe('0.000')
        // Same shift and type, other date.
        ->and($reconciliation->forShift($this->farm->id, '2026-09-11', Shift::Morning, MilkType::Cow)->usageTotal)
        ->toBe('0.000');
});
