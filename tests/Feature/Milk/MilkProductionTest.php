<?php

use App\Actions\Farms\SetPrimaryFarm;
use App\Actions\Milk\SaveMilkProduction;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\AuditLog;
use App\Models\Farm;
use App\Models\MilkProduction;
use App\Services\BusinessContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Milk production: the record identity, the save path, and the validation floor.
 *
 * The identity is the thing to get right. One row per farm, date and shift,
 * carrying both milk types as columns (docs/DECISIONS.md D30). Everything else in
 * Phase 3 depends on that being true, because it is what lets "has this shift been
 * recorded?" be a single fact.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';
});

/*
|--------------------------------------------------------------------------
| A. The authoritative production model, locked at schema level
|--------------------------------------------------------------------------
*/

test('production has no milk_type column and holds both types as columns', function () {
    expect(Schema::hasColumn('milk_productions', 'milk_type'))->toBeFalse()
        ->and(Schema::hasColumn('milk_productions', 'cow_milk_quantity'))->toBeTrue()
        ->and(Schema::hasColumn('milk_productions', 'buffalo_milk_quantity'))->toBeTrue();
});

test('the production unique key is exactly farm, date and shift', function () {
    $indexes = collect(Schema::getIndexes('milk_productions'))
        ->filter(fn (array $index): bool => (bool) $index['unique'])
        // The primary key is unique too; it is not the constraint under test.
        ->reject(fn (array $index): bool => (bool) $index['primary'])
        ->values();

    expect($indexes)->toHaveCount(1)
        ->and($indexes[0]['columns'])->toBe(['farm_id', 'production_date', 'shift']);
});

test('production quantities are decimal and the business date is a DATE', function () {
    expect(Schema::getColumnType('milk_productions', 'cow_milk_quantity'))->toBe('decimal')
        ->and(Schema::getColumnType('milk_productions', 'buffalo_milk_quantity'))->toBe('decimal')
        ->and(Schema::getColumnType('milk_productions', 'production_date'))->toBe('date')
        ->and(Schema::getColumnType('milk_productions', 'shift'))->toBe('varchar');
});

test('the decimal columns carry exactly three decimal places', function (string $column) {
    $type = DB::selectOne(
        'SELECT COLUMN_TYPE as column_type FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['milk_productions', $column]
    );

    expect(strtolower((string) $type->column_type))->toBe('decimal(10,3)');
})->with(['cow_milk_quantity', 'buffalo_milk_quantity']);

test('many dates and both shifts coexist for one farm', function () {
    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        foreach (Shift::cases() as $shift) {
            MilkProduction::factory()->for($this->farm)->shift($shift)->on($date)->create();
        }
    }

    expect(MilkProduction::query()->count())->toBe(6)
        ->and(MilkProduction::query()->where('shift', Shift::Morning->value)->count())->toBe(3)
        ->and(MilkProduction::query()->where('shift', Shift::Evening->value)->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| B. Creation
|--------------------------------------------------------------------------
*/

test('saving a morning shift creates one row with both quantities exact', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.500', 'buffalo' => '4.250']],
    ])->assertRedirect();

    expect(MilkProduction::query()->count())->toBe(1);

    $row = MilkProduction::query()->firstOrFail();

    expect($row->shift)->toBe(Shift::Morning)
        ->and($row->production_date->toDateString())->toBe($this->date)
        ->and($row->farm_id)->toBe($this->farm->id)
        ->and($row->cow_milk_quantity)->toBe('10.500')
        ->and($row->buffalo_milk_quantity)->toBe('4.250')
        ->and($row->created_by)->toBe($this->admin->id)
        ->and($row->updated_by)->toBe($this->admin->id);
});

test('creating production writes an audit record carrying the quantities', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.500', 'buffalo' => '4.250']],
    ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'milk_production')->latest('id')->firstOrFail();

    expect($log->action->value)->toBe('created')
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->new_values['cow_milk_quantity'])->toBe('10.500')
        ->and($log->new_values['buffalo_milk_quantity'])->toBe('4.250')
        ->and($log->new_values['shift'])->toBe('morning');
});

test('the evening shift becomes its own second row for the same date', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.500', 'buffalo' => '4.250']],
    ])->assertRedirect();

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['evening' => ['cow' => '9.000', 'buffalo' => '3.500']],
    ])->assertRedirect();

    expect(MilkProduction::query()->count())->toBe(2);

    $evening = MilkProduction::query()->where('shift', Shift::Evening->value)->firstOrFail();

    expect($evening->cow_milk_quantity)->toBe('9.000');
});

test('the matrix saves both shifts of a day in one request', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => [
            'morning' => ['cow' => '10.500', 'buffalo' => '4.250'],
            'evening' => ['cow' => '9.000', 'buffalo' => '3.500'],
        ],
    ])->assertRedirect();

    expect(MilkProduction::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| C. Update in place
|--------------------------------------------------------------------------
*/

test('re-saving a shift updates the same row and leaves the other shift alone', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => [
            'morning' => ['cow' => '10.500', 'buffalo' => '4.250'],
            'evening' => ['cow' => '9.000', 'buffalo' => '3.500'],
        ],
    ])->assertRedirect();

    $morningId = MilkProduction::query()->where('shift', Shift::Morning->value)->value('id');

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '11.000', 'buffalo' => '4.500']],
    ])->assertRedirect();

    expect(MilkProduction::query()->count())->toBe(2);

    $morning = MilkProduction::query()->where('shift', Shift::Morning->value)->firstOrFail();
    $evening = MilkProduction::query()->where('shift', Shift::Evening->value)->firstOrFail();

    expect($morning->id)->toBe($morningId)
        ->and($morning->cow_milk_quantity)->toBe('11.000')
        ->and($morning->buffalo_milk_quantity)->toBe('4.500')
        // Untouched.
        ->and($evening->cow_milk_quantity)->toBe('9.000')
        ->and($evening->buffalo_milk_quantity)->toBe('3.500');
});

test('a correction keeps the original creator and records the new editor', function () {
    $creator = userWithPermissions(['milk.production.view', 'milk.production.create']);
    $editor = userWithPermissions(['milk.production.view', 'milk.production.create']);

    $this->actingAs($creator)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $this->actingAs($editor)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '11.250', 'buffalo' => '0']],
    ])->assertRedirect();

    $row = MilkProduction::query()->firstOrFail();

    // Who first recorded the shift is usually the more interesting of the two,
    // so it survives the correction.
    expect($row->created_by)->toBe($creator->id)
        ->and($row->updated_by)->toBe($editor->id);
});

test('an update audits the old and new quantities, so the change is reconstructable', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '11.250', 'buffalo' => '0']],
    ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'milk_production')
        ->where('action', 'updated')->latest('id')->firstOrFail();

    expect($log->old_values['cow_milk_quantity'])->toBe('10.000')
        ->and($log->new_values['cow_milk_quantity'])->toBe('11.250')
        // Buffalo did not change, so it is not recorded as a change.
        ->and($log->new_values)->not->toHaveKey('buffalo_milk_quantity');

    // The correction is one row, not a second competing record.
    expect(MilkProduction::query()->count())->toBe(1);
});

test('re-saving unchanged values writes no audit record at all', function () {
    $payload = [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '4.000']],
    ];

    $this->actingAs($this->admin)->post(route('milk.production.store'), $payload)->assertRedirect();

    $after = AuditLog::query()->where('auditable_type', 'milk_production')->count();

    $this->actingAs($this->admin)->post(route('milk.production.store'), $payload)->assertRedirect();

    expect(AuditLog::query()->where('auditable_type', 'milk_production')->count())->toBe($after);
});

/*
|--------------------------------------------------------------------------
| D. Duplicate protection, in the application and in the database
|--------------------------------------------------------------------------
*/

test('the action never produces a second row for the same farm, date and shift', function () {
    $action = app(SaveMilkProduction::class);

    $this->actingAs($this->admin);

    $action->forShift($this->date, Shift::Morning, '10.000', '5.000');
    $action->forShift($this->date, Shift::Morning, '12.000', '6.000');
    $action->forShift($this->date, Shift::Morning, '13.000', '7.000');

    expect(MilkProduction::query()->count())->toBe(1)
        ->and(MilkProduction::query()->value('cow_milk_quantity'))->toBe('13.000');
});

test('the database rejects a duplicate inserted behind the application', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)->create();

    // Straight to the table, bypassing the model and the action entirely.
    expect(fn () => DB::table('milk_productions')->insert([
        'farm_id' => $this->farm->id,
        'production_date' => $this->date,
        'shift' => Shift::Morning->value,
        'cow_milk_quantity' => '1.000',
        'buffalo_milk_quantity' => '1.000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(MilkProduction::query()->count())->toBe(1);
});

test('the same date with a different shift is allowed', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)->create();
    MilkProduction::factory()->for($this->farm)->evening()->on($this->date)->create();

    expect(MilkProduction::query()->count())->toBe(2);
});

test('the same shift on a different date is allowed', function () {
    MilkProduction::factory()->for($this->farm)->morning()->on('2026-09-10')->create();
    MilkProduction::factory()->for($this->farm)->morning()->on('2026-09-11')->create();

    expect(MilkProduction::query()->count())->toBe(2);
});

test('the schema permits the same date and shift on a different farm', function () {
    // V1 resolves the primary farm automatically, so this never happens through
    // the UI. The constraint is still scoped per farm, because a second farm must
    // not be blocked by the first farm's records.
    $second = Farm::factory()->for($this->business)->create(['code' => 'SECOND']);

    MilkProduction::factory()->for($this->farm)->morning()->on($this->date)->create();
    MilkProduction::factory()->for($second)->morning()->on($this->date)->create();

    expect(MilkProduction::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| E. The primary farm is resolved, never asked for
|--------------------------------------------------------------------------
*/

test('production is recorded against the primary farm with no farm input', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '0']],
    ])->assertRedirect();

    expect(MilkProduction::query()->value('farm_id'))->toBe($this->farm->id);
});

test('a farm id posted in the request cannot redirect the save', function () {
    $other = Farm::factory()->for($this->business)->create(['code' => 'OTHER']);

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'farm_id' => $other->id,
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '0']],
    ])->assertRedirect();

    // The posted id is never read: the action resolves the primary farm itself.
    expect(MilkProduction::query()->value('farm_id'))->toBe($this->farm->id)
        ->and(MilkProduction::query()->where('farm_id', $other->id)->exists())->toBeFalse();
});

test('there is no farm selector on the production screen', function () {
    $this->actingAs($this->admin)->get(route('milk.production.index'))
        ->assertOk()
        ->assertDontSee('name="farm_id"', false);
});

test('promoting another farm moves future production without moving history', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => '2026-09-10',
        'shifts' => ['morning' => ['cow' => '10.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $historical = MilkProduction::query()->firstOrFail();

    $second = Farm::factory()->for($this->business)->create(['code' => 'SECOND']);
    app(SetPrimaryFarm::class)->handle($second);
    app(BusinessContext::class)->forget();

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => '2026-09-11',
        'shifts' => ['morning' => ['cow' => '20.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $new = MilkProduction::query()->whereDate('production_date', '2026-09-11')->firstOrFail();

    expect($new->farm_id)->toBe($second->id)
        // History stays where it was recorded. Re-pointing it at the new primary
        // farm would rewrite what happened.
        ->and($historical->fresh()->farm_id)->toBe($this->farm->id);
});

/*
|--------------------------------------------------------------------------
| F. Validation
|--------------------------------------------------------------------------
*/

test('a negative quantity is rejected for either milk type', function (string $field) {
    $shifts = ['morning' => ['cow' => '5.000', 'buffalo' => '5.000']];
    $shifts['morning'][$field] = '-0.001';

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => $shifts,
    ])->assertSessionHasErrors("shifts.morning.{$field}");

    expect(MilkProduction::query()->count())->toBe(0);
})->with(['cow', 'buffalo']);

test('a fourth decimal place is rejected rather than rounded into the column', function () {
    // MySQL would happily store 1.235 for an input of 1.2345. The arithmetic
    // would then be exactly right about a figure nobody entered.
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '1.2345', 'buffalo' => '0']],
    ])->assertSessionHasErrors('shifts.morning.cow');

    expect(MilkProduction::query()->count())->toBe(0);
});

test('three decimal places are accepted and stored exactly', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '0.001', 'buffalo' => '9999999.999']],
    ])->assertRedirect();

    $row = MilkProduction::query()->firstOrFail();

    expect($row->cow_milk_quantity)->toBe('0.001')
        ->and($row->buffalo_milk_quantity)->toBe('9999999.999');
});

test('a quantity beyond the column range is rejected', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '10000000.000', 'buffalo' => '0']],
    ])->assertSessionHasErrors('shifts.morning.cow');

    expect(MilkProduction::query()->count())->toBe(0);
});

test('an unrecognised shift key is refused rather than silently skipped', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['afternoon' => ['cow' => '5.000', 'buffalo' => '5.000']],
    ])->assertSessionHasErrors('shifts');

    expect(MilkProduction::query()->count())->toBe(0);
});

test('an invalid date is rejected', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => 'not-a-date',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '5.000']],
    ])->assertSessionHasErrors('production_date');

    expect(MilkProduction::query()->count())->toBe(0);
});

test('notes longer than the limit are rejected', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '5.000', 'notes' => str_repeat('a', 1001)]],
    ])->assertSessionHasErrors('shifts.morning.notes');
});

test('notes are stored when given and cleared when blanked', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '5.000', 'notes' => 'Cooler failed briefly']],
    ])->assertRedirect();

    expect(MilkProduction::query()->value('notes'))->toBe('Cooler failed briefly');

    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '5.000', 'notes' => '']],
    ])->assertRedirect();

    expect(MilkProduction::query()->value('notes'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| G. Zero is a legitimate recorded value
|--------------------------------------------------------------------------
*/

test('one milk type may be zero while the other is positive', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '0', 'buffalo' => '5.000']],
    ])->assertRedirect();

    $row = MilkProduction::query()->firstOrFail();

    expect($row->cow_milk_quantity)->toBe('0.000')
        ->and($row->buffalo_milk_quantity)->toBe('5.000');
});

test('both types may be zero, and that is production entered rather than missing', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '0', 'buffalo' => '0']],
    ])->assertRedirect();

    // The row exists, so somebody has stated what this shift produced.
    expect(MilkProduction::query()->count())->toBe(1);

    $row = MilkProduction::query()->firstOrFail();

    expect($row->totalQuantity())->toBe('0.000')
        ->and($row->quantityFor(MilkType::Cow))->toBe('0.000')
        ->and($row->quantityFor(MilkType::Buffalo))->toBe('0.000');
});

test('an omitted quantity field is recorded as zero, because the shift was saved', function () {
    $this->actingAs($this->admin)->post(route('milk.production.store'), [
        'production_date' => $this->date,
        'shifts' => ['morning' => ['cow' => '5.000']],
    ])->assertRedirect();

    expect(MilkProduction::query()->value('buffalo_milk_quantity'))->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| H. Model helpers
|--------------------------------------------------------------------------
*/

test('quantityFor maps each milk type to its own column', function () {
    $row = MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('12.500', '6.250')->create();

    expect($row->quantityFor(MilkType::Cow))->toBe('12.500')
        ->and($row->quantityFor(MilkType::Buffalo))->toBe('6.250')
        ->and($row->totalQuantity())->toBe('18.750');
});

test('columnFor names the real column for every milk type', function () {
    foreach (MilkType::cases() as $milkType) {
        expect(Schema::hasColumn('milk_productions', MilkProduction::columnFor($milkType)))->toBeTrue();
    }
});

test('there is no route for deleting production', function () {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->values();

    expect($names)->not->toContain('milk.production.destroy')
        ->and($names)->not->toContain('milk.production.delete');

    // And no DELETE verb is registered anywhere under /milk.
    $destructive = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_starts_with($route->uri(), 'milk'));

    expect($destructive)->toBeEmpty();
});
