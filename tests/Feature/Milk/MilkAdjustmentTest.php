<?php

use App\Actions\Milk\CancelMilkAdjustment;
use App\Actions\Milk\CancelMilkUsage;
use App\Actions\Milk\RecordMilkAdjustment;
use App\Actions\Milk\RecordMilkUsage;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * Authorised milk adjustments: the exception route.
 *
 * Two properties carry the whole design. The direction is explicit and the stored
 * quantity is always positive (docs/DECISIONS.md D31). And nothing creates an
 * adjustment automatically — if milk runs short the allocation is refused and a
 * person decides, because an auto-created adjustment is exactly the silent
 * balancing record MASTER_SPEC section 15 forbids.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    $this->reconciliation = app(CalculateMilkReconciliation::class);

    $this->cowMorning = fn () => $this->reconciliation->forShift(
        $this->farm->id, $this->date, Shift::Morning, MilkType::Cow
    );

    $this->produce = fn (string $cow, string $buffalo = '0.000') => MilkProduction::factory()
        ->for($this->farm)->morning()->on($this->date)->quantities($cow, $buffalo)->create();

    $this->adjust = fn (AdjustmentDirection $direction, string $quantity, string $reason = 'Measured differently at the collection point') => app(RecordMilkAdjustment::class)
        ->handle($this->date, Shift::Morning, MilkType::Cow, $direction, $quantity, $reason);

    $this->use = fn (string $quantity) => app(RecordMilkUsage::class)
        ->handle($this->date, Shift::Morning, MilkType::Cow, MilkUsageType::CalfFeeding, $quantity);

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. Representation: explicit direction, positive quantity (D31)
|--------------------------------------------------------------------------
*/

test('the adjustments table stores a direction and never a signed quantity', function () {
    expect(Schema::hasColumn('milk_adjustments', 'direction'))->toBeTrue()
        ->and(Schema::getColumnType('milk_adjustments', 'direction'))->toBe('varchar')
        ->and(Schema::getColumnType('milk_adjustments', 'quantity'))->toBe('decimal');
});

test('the reason column is not nullable, because it justifies the row existing', function () {
    $column = DB::selectOne(
        'SELECT IS_NULLABLE as is_nullable FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['milk_adjustments', 'reason']
    );

    expect(strtoupper((string) $column->is_nullable))->toBe('NO');
});

test('an increase stores a positive quantity with a positive effect', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '1.250');

    $adjustment = MilkAdjustment::query()->firstOrFail();

    expect($adjustment->direction)->toBe(AdjustmentDirection::Increase)
        ->and($adjustment->quantity)->toBe('1.250')
        ->and($adjustment->signedQuantity())->toBe('1.250')
        ->and($adjustment->direction->sign())->toBe(1);

    // On disk, too: no minus sign anywhere.
    expect(DB::table('milk_adjustments')->value('quantity'))->toBe('1.250');
});

test('a decrease stores a positive quantity with a negative effect', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Decrease, '0.500');

    $adjustment = MilkAdjustment::query()->firstOrFail();

    expect($adjustment->direction)->toBe(AdjustmentDirection::Decrease)
        // Positive in the column...
        ->and($adjustment->quantity)->toBe('0.500')
        // ...negative only in the arithmetic.
        ->and($adjustment->signedQuantity())->toBe('-0.500')
        ->and($adjustment->direction->sign())->toBe(-1);

    expect(DB::table('milk_adjustments')->value('quantity'))->toBe('0.500');
});

test('an increase and a decrease net exactly', function () {
    ($this->produce)('10.000');

    ($this->adjust)(AdjustmentDirection::Increase, '1.250');
    ($this->adjust)(AdjustmentDirection::Decrease, '0.500');

    $result = ($this->cowMorning)();

    expect($result->increaseTotal)->toBe('1.250')
        ->and($result->decreaseTotal)->toBe('0.500')
        ->and($result->adjustmentTotal)->toBe('0.750')
        ->and($result->available)->toBe('10.750');
});

test('an increase and an equal decrease net to zero but both stay visible', function () {
    ($this->produce)('10.000');

    ($this->adjust)(AdjustmentDirection::Increase, '2.000');
    ($this->adjust)(AdjustmentDirection::Decrease, '2.000');

    $result = ($this->cowMorning)();

    expect($result->adjustmentTotal)->toBe('0.000')
        // Netting them into one figure would hide that two exceptions were
        // recorded, so the screen is told to show both.
        ->and($result->hasAdjustments())->toBeTrue()
        ->and($result->increaseTotal)->toBe('2.000')
        ->and($result->decreaseTotal)->toBe('2.000');
});

test('an invalid direction is rejected', function () {
    ($this->produce)('10.000');

    $this->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => 'sideways',
        'quantity' => '1.000',
        'reason' => 'Trying an unsupported direction',
    ])->assertSessionHasErrors('direction');

    expect(MilkAdjustment::query()->count())->toBe(0);
});

test('a zero or negative adjustment quantity is refused', function (string $quantity) {
    ($this->produce)('10.000');

    $this->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => $quantity,
        'reason' => 'A quantity that says nothing happened',
    ])->assertSessionHasErrors('quantity');

    expect(MilkAdjustment::query()->count())->toBe(0);
})->with(['0', '0.000', '-1.000']);

test('the action refuses a non-positive quantity even when called directly', function () {
    ($this->produce)('10.000');

    expect(fn () => ($this->adjust)(AdjustmentDirection::Increase, '0.000'))
        ->toThrow(ValidationException::class);

    expect(MilkAdjustment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| B. The reason is mandatory and must say something
|--------------------------------------------------------------------------
*/

test('an adjustment without a reason is refused by the form', function (string $reason) {
    ($this->produce)('10.000');

    $this->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '1.000',
        'reason' => $reason,
    ])->assertSessionHasErrors('reason');

    expect(MilkAdjustment::query()->count())->toBe(0);
})->with([
    'empty' => [''],
    'one character' => ['x'],
    'four characters' => ['abcd'],
]);

test('the action refuses a reason that is only whitespace', function () {
    ($this->produce)('10.000');

    // Long enough to pass the form's min:5, empty once trimmed. The action is the
    // backstop, so a direct caller cannot record a blank justification either.
    expect(fn () => ($this->adjust)(AdjustmentDirection::Increase, '1.000', '        '))
        ->toThrow(ValidationException::class);

    expect(MilkAdjustment::query()->count())->toBe(0);
});

test('the reason is stored verbatim, as entered', function () {
    ($this->produce)('10.000');

    $reason = 'Collection point measured 0.500 L more than the shed record';
    ($this->adjust)(AdjustmentDirection::Increase, '0.500', $reason);

    expect(MilkAdjustment::query()->value('reason'))->toBe($reason);
});

/*
|--------------------------------------------------------------------------
| C. Creation and its effect on availability
|--------------------------------------------------------------------------
*/

test('an increase of two litres on ten makes twelve available', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '2.000');

    $result = ($this->cowMorning)();

    expect($result->production)->toBe('10.000')
        ->and($result->adjustmentTotal)->toBe('2.000')
        ->and($result->available)->toBe('12.000')
        ->and($result->remaining)->toBe('12.000');
});

test('a following decrease of one litre nets to eleven available', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '2.000');
    ($this->adjust)(AdjustmentDirection::Decrease, '1.000');

    $result = ($this->cowMorning)();

    expect($result->adjustmentTotal)->toBe('1.000')
        ->and($result->available)->toBe('11.000');
});

test('each adjustment persists exactly once with its full context', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '2.000');

    expect(MilkAdjustment::query()->count())->toBe(1);

    $adjustment = MilkAdjustment::query()->firstOrFail();

    expect($adjustment->farm_id)->toBe($this->farm->id)
        ->and($adjustment->adjustment_date->toDateString())->toBe($this->date)
        ->and($adjustment->shift)->toBe(Shift::Morning)
        ->and($adjustment->milk_type)->toBe(MilkType::Cow)
        ->and($adjustment->status)->toBe(TransactionStatus::Active)
        ->and($adjustment->created_by)->toBe($this->admin->id);
});

test('an adjustment can raise availability above production for a shift that has usage', function () {
    ($this->produce)('10.000');
    ($this->use)('10.000');

    expect(($this->cowMorning)()->remaining)->toBe('0.000');

    // The authorised route to more milk: state that more was available, and why.
    ($this->adjust)(AdjustmentDirection::Increase, '2.000');

    $result = ($this->cowMorning)();

    expect($result->available)->toBe('12.000')
        ->and($result->remaining)->toBe('2.000');

    // And now the allocation that was refused becomes possible.
    ($this->use)('2.000');

    expect(($this->cowMorning)()->remaining)->toBe('0.000');
});

test('no adjustment appears merely because an allocation would have overrun', function () {
    ($this->produce)('10.000');

    expect(fn () => ($this->use)('15.000'))->toThrow(ValidationException::class);

    // The refusal writes nothing at all -- no usage, and emphatically no
    // compensating adjustment.
    expect(MilkAdjustment::query()->count())->toBe(0)
        ->and(MilkUsage::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D. A decrease may not remove milk already allocated
|--------------------------------------------------------------------------
*/

test('a decrease that would leave available below allocated is refused', function () {
    ($this->produce)('10.000');
    ($this->use)('8.000');

    // Available would become 7.000 against 8.000 allocated.
    expect(fn () => ($this->adjust)(AdjustmentDirection::Decrease, '3.000'))
        ->toThrow(ValidationException::class);

    expect(MilkAdjustment::query()->count())->toBe(0)
        ->and(($this->cowMorning)()->available)->toBe('10.000');
});

test('a decrease down to exactly the allocated amount is allowed', function () {
    ($this->produce)('10.000');
    ($this->use)('8.000');

    ($this->adjust)(AdjustmentDirection::Decrease, '2.000');

    $result = ($this->cowMorning)();

    expect($result->available)->toBe('8.000')
        ->and($result->allocated)->toBe('8.000')
        ->and($result->remaining)->toBe('0.000')
        ->and($result->isOverAllocated())->toBeFalse();
});

test('the decrease boundary is exact to the millilitre', function () {
    ($this->produce)('10.000');
    ($this->use)('8.000');

    // 2.001 would leave 7.999 available against 8.000 allocated.
    expect(fn () => ($this->adjust)(AdjustmentDirection::Decrease, '2.001'))
        ->toThrow(ValidationException::class);

    expect(MilkAdjustment::query()->count())->toBe(0);
});

test('the refusal names the remaining milk so the user knows the limit', function () {
    ($this->produce)('10.000');
    ($this->use)('8.000');

    try {
        ($this->adjust)(AdjustmentDirection::Decrease, '3.000');
        $this->fail('Expected the decrease to be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['quantity'][0])->toBe(
            __('milk.errors.decrease_exceeds_remaining', [
                'requested' => '3.000 '.__('milk.litres_short'),
                'remaining' => '2.000 '.__('milk.litres_short'),
            ])
        );
    }
});

test('a decrease on a shift with no allocation is limited only by availability', function () {
    ($this->produce)('10.000');

    ($this->adjust)(AdjustmentDirection::Decrease, '10.000');

    expect(($this->cowMorning)()->available)->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| E. Cancelling an adjustment cannot create a negative remainder
|--------------------------------------------------------------------------
*/

test('cancelling an increase that allocations depend on is refused', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '5.000');
    ($this->use)('14.000');

    $adjustment = MilkAdjustment::query()->firstOrFail();
    $auditCount = AuditLog::query()->where('auditable_type', 'milk_adjustment')->count();

    // Withdrawing 5.000 would leave 10.000 available against 14.000 allocated.
    expect(fn () => app(CancelMilkAdjustment::class)->handle($adjustment, 'Recorded in error'))
        ->toThrow(ValidationException::class);

    expect($adjustment->fresh()->status)->toBe(TransactionStatus::Active)
        ->and($adjustment->fresh()->cancelled_at)->toBeNull()
        // No cancellation event falsely recorded.
        ->and(AuditLog::query()->where('auditable_type', 'milk_adjustment')->count())->toBe($auditCount)
        ->and(AuditLog::query()->where('auditable_type', 'milk_adjustment')
            ->where('action', 'cancelled')->count())->toBe(0);
});

test('the refusal explains that the usage has to go first', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '5.000');
    ($this->use)('14.000');

    try {
        app(CancelMilkAdjustment::class)->handle(MilkAdjustment::query()->firstOrFail(), 'Recorded in error');
        $this->fail('Expected the cancellation to be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['cancellation_reason'][0])->toBe(
            __('milk.errors.cancel_would_over_allocate', [
                'quantity' => '5.000 '.__('milk.litres_short'),
                'remaining' => '1.000 '.__('milk.litres_short'),
            ])
        );
    }
});

test('once the dependent usage is cancelled, the increase can be withdrawn', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '5.000');
    ($this->use)('14.000');

    $adjustment = MilkAdjustment::query()->firstOrFail();

    app(CancelMilkUsage::class)
        ->handle(MilkUsage::query()->firstOrFail(), 'Allocated against milk that was not there');

    app(CancelMilkAdjustment::class)->handle($adjustment->fresh(), 'Measurement was wrong after all');

    expect($adjustment->fresh()->status)->toBe(TransactionStatus::Cancelled);

    $result = ($this->cowMorning)();

    expect($result->available)->toBe('10.000')
        ->and($result->adjustmentTotal)->toBe('0.000')
        ->and($result->remaining)->toBe('10.000');
});

test('cancelling an increase is allowed when the milk is still unallocated', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '5.000');

    app(CancelMilkAdjustment::class)->handle(MilkAdjustment::query()->firstOrFail(), 'Duplicate of an earlier entry');

    expect(($this->cowMorning)()->available)->toBe('10.000');
});

test('cancelling a decrease restores availability and is always safe', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Decrease, '4.000');
    ($this->use)('6.000');

    expect(($this->cowMorning)()->remaining)->toBe('0.000');

    // Withdrawing a decrease only ever adds milk back, so it cannot over-allocate.
    app(CancelMilkAdjustment::class)->handle(MilkAdjustment::query()->firstOrFail(), 'The shortfall was recounted');

    $result = ($this->cowMorning)();

    expect($result->available)->toBe('10.000')
        ->and($result->remaining)->toBe('4.000');
});

test('a cancelled adjustment stops affecting reconciliation but stays in the table', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '3.000');

    app(CancelMilkAdjustment::class)->handle(MilkAdjustment::query()->firstOrFail(), 'Withdrawn after review');

    expect(MilkAdjustment::query()->count())->toBe(1)
        ->and(MilkAdjustment::query()->active()->count())->toBe(0)
        ->and(($this->cowMorning)()->adjustmentTotal)->toBe('0.000')
        ->and(($this->cowMorning)()->available)->toBe('10.000');
});

test('cancelling an adjustment twice is refused', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '3.000');

    $adjustment = MilkAdjustment::query()->firstOrFail();

    app(CancelMilkAdjustment::class)->handle($adjustment, 'First cancellation');

    expect(fn () => app(CancelMilkAdjustment::class)->handle($adjustment->fresh(), 'Second attempt'))
        ->toThrow(ValidationException::class);

    expect($adjustment->fresh()->cancellation_reason)->toBe('First cancellation');
});

test('cancelling an adjustment requires a reason', function (string $reason) {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '3.000');

    $adjustment = MilkAdjustment::query()->firstOrFail();

    $this->put(route('milk.adjustments.cancel', $adjustment), ['cancellation_reason' => $reason])
        ->assertSessionHasErrors('cancellation_reason');

    expect($adjustment->fresh()->isCancelled())->toBeFalse();
})->with(['empty' => [''], 'too short' => ['no']]);

test('there is no route for deleting an adjustment', function () {
    $destructive = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_contains($route->uri(), 'adjustment'));

    expect($destructive)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| F. Audit, which matters more here than anywhere else in Phase 3
|--------------------------------------------------------------------------
*/

test('creating an adjustment audits the direction, quantity, reason and context', function () {
    ($this->produce)('10.000');

    $reason = 'Collection point measured 2.000 L more than the shed record';
    ($this->adjust)(AdjustmentDirection::Increase, '2.000', $reason);

    $adjustment = MilkAdjustment::query()->firstOrFail();

    $log = AuditLog::query()->where('auditable_type', 'milk_adjustment')->latest('id')->firstOrFail();

    expect($log->action->value)->toBe('created')
        ->and($log->auditable_id)->toBe($adjustment->id)
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->new_values['direction'])->toBe('increase')
        ->and($log->new_values['quantity'])->toBe('2.000')
        ->and($log->new_values['reason'])->toBe($reason)
        ->and($log->new_values['adjustment_date'])->toBe($this->date)
        ->and($log->new_values['shift'])->toBe('morning')
        ->and($log->new_values['milk_type'])->toBe('cow')
        // The subject label identifies the record without loading it.
        ->and($log->subject)->toContain(Shift::Morning->label())
        ->and($log->subject)->toContain(MilkType::Cow->label());
});

test('the audit type is a stable alias, not a class name', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '1.000');

    $stored = DB::table('audit_logs')->where('auditable_type', 'milk_adjustment')->first();

    expect($stored)->not->toBeNull()
        ->and($stored->auditable_type)->toBe('milk_adjustment')
        ->and($stored->auditable_type)->not->toContain('\\')
        ->and($stored->auditable_type)->not->toContain('App');
});

test('cancelling an adjustment audits the reason and the actor', function () {
    ($this->produce)('10.000');
    ($this->adjust)(AdjustmentDirection::Increase, '1.000');

    $adjustment = MilkAdjustment::query()->firstOrFail();

    $canceller = userWithPermissions(['milk.adjustment.create', 'milk.adjustment.cancel']);
    $this->actingAs($canceller);

    app(CancelMilkAdjustment::class)->handle($adjustment, 'Recount showed the original figure was right');

    $log = AuditLog::query()->where('auditable_type', 'milk_adjustment')
        ->where('action', 'cancelled')->latest('id')->firstOrFail();

    expect($log->auditable_id)->toBe($adjustment->id)
        ->and($log->user_id)->toBe($canceller->id)
        ->and($log->new_values['cancellation_reason'])
        ->toBe('Recount showed the original figure was right');
});

test('the adjustment audit carries no request payload beyond the recorded fields', function () {
    ($this->produce)('10.000');

    $this->post(route('milk.adjustments.store'), [
        'adjustment_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '1.000',
        'reason' => 'A genuine measurement difference',
        // Noise a caller might post alongside. It must not reach the log.
        '_token' => 'irrelevant',
        'password' => 'should-never-be-recorded',
    ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'milk_adjustment')->latest('id')->firstOrFail();

    // Sorted: a MySQL JSON column does not preserve insertion order, and the set
    // of recorded fields is what matters rather than their sequence.
    $keys = array_keys($log->new_values);
    sort($keys);

    expect($keys)->toBe([
        'adjustment_date', 'direction', 'milk_type', 'quantity', 'reason', 'shift',
    ]);

    expect(json_encode($log->new_values))->not->toContain('should-never-be-recorded')
        ->and($log->new_values)->not->toHaveKey('password')
        ->and($log->new_values)->not->toHaveKey('_token');
});
