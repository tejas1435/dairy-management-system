<?php

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\MilkUsage;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\MorphMap;
use Database\Seeders\MilkProductionSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Phase 3 development seed, the morph aliases, and the phase boundary.
 *
 * The seeder is checked for idempotency because every seeder in this project is
 * run repeatedly during development and on every deploy. The boundary is checked
 * because a phase that quietly grows into the next one is how scope is lost.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
});

/*
|--------------------------------------------------------------------------
| A. Seeder idempotency
|--------------------------------------------------------------------------
*/

test('running the milk seeder three times creates no duplicates', function () {
    $this->seed(MilkProductionSeeder::class);

    $counts = [
        'production' => MilkProduction::query()->count(),
        'usage' => MilkUsage::query()->count(),
        'adjustment' => MilkAdjustment::query()->count(),
    ];

    // Something must have been seeded, or this test proves nothing.
    expect($counts['production'])->toBeGreaterThan(0)
        ->and($counts['usage'])->toBeGreaterThan(0)
        ->and($counts['adjustment'])->toBeGreaterThan(0);

    $this->seed(MilkProductionSeeder::class);
    $this->seed(MilkProductionSeeder::class);

    expect(MilkProduction::query()->count())->toBe($counts['production'])
        ->and(MilkUsage::query()->count())->toBe($counts['usage'])
        ->and(MilkAdjustment::query()->count())->toBe($counts['adjustment']);
});

test('the deliberately unentered evening shift stays unentered across re-seeds', function () {
    /*
     * The seed leaves yesterday's evening missing so the "Production not entered"
     * state is visible on a fresh installation. A seeder that filled it in on the
     * second run would quietly destroy the one example of the state the module is
     * built around.
     */
    $this->seed(MilkProductionSeeder::class);

    $farmId = app(BusinessContext::class)->primaryFarmId();
    $yesterday = now()->subDay()->toDateString();

    $missing = fn (): bool => ! MilkProduction::query()
        ->forShift($farmId, $yesterday, Shift::Evening)
        ->exists();

    expect($missing())->toBeTrue();

    $this->seed(MilkProductionSeeder::class);

    expect($missing())->toBeTrue()
        // And its morning counterpart is present, so the gap is the evening only.
        ->and(MilkProduction::query()->forShift($farmId, $yesterday, Shift::Morning)->exists())
        ->toBeTrue();
});

test('the seeded data is internally consistent: no shift is over-allocated', function () {
    $this->seed(MilkProductionSeeder::class);

    $farmId = app(BusinessContext::class)->primaryFarmId();
    $engine = app(CalculateMilkReconciliation::class);

    $dates = MilkProduction::query()->pluck('production_date')
        ->map(fn ($date): string => $date->toDateString())->unique();

    foreach ($dates as $date) {
        foreach (Shift::cases() as $shift) {
            foreach (MilkType::cases() as $milkType) {
                $result = $engine->forShift($farmId, $date, $shift, $milkType);

                expect($result->isOverAllocated())->toBeFalse(
                    "Seeded {$date} {$shift->value} {$milkType->value} is over-allocated."
                );
            }
        }
    }
});

test('the milk seeder creates no sales of its own', function () {
    /*
     * This guard has now been through three forms, and each one protected the same
     * thing from a different direction. Originally: `milk_sales` does not exist.
     * Then, once Phase 4 created it: the milk seeder does not invent deliveries.
     * Now that Phase 4 Pass 3 *does* seed deliveries, what it pins is the division
     * of responsibility — sales come from `CustomerSalesSeeder`, through the real
     * domain action, where availability and pricing apply. A sale written directly
     * by the milk seeder would bypass both.
     *
     * The deliveries themselves, and the arithmetic they have to satisfy, are
     * asserted in `SeedIntegrityTest`.
     */
    $this->seed(MilkProductionSeeder::class);

    expect(Schema::hasTable('milk_sales'))->toBeTrue()
        ->and(MilkSale::query()->count())->toBe(0);

    // And the seeder that does create them is wired into the chain.
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))
        ->toContain('CustomerSalesSeeder::class');
});

/*
|--------------------------------------------------------------------------
| B. Morph aliases
|--------------------------------------------------------------------------
*/

test('each Phase 3 model resolves to its stable alias', function (string $alias, string $class) {
    expect(Relation::getMorphedModel($alias))->toBe($class)
        ->and((new $class)->getMorphClass())->toBe($alias);
})->with([
    ['milk_production', MilkProduction::class],
    ['milk_usage', MilkUsage::class],
    ['milk_adjustment', MilkAdjustment::class],
]);

test('the Phase 3 aliases are registered in the map', function (string $alias) {
    expect(MorphMap::map())->toHaveKey($alias);
})->with(['milk_production', 'milk_usage', 'milk_adjustment']);

test('an audit record for a milk model stores the alias, not a class name', function (string $modelClass, string $alias) {
    $farm = $this->business->primaryFarm();
    $this->actingAs(superAdmin());

    $model = $modelClass::factory()->for($farm)->create();

    app(AuditLogger::class)->created($model, ['probe' => 'value']);

    $stored = DB::table('audit_logs')->latest('id')->first();

    expect($stored->auditable_type)->toBe($alias)
        ->and($stored->auditable_type)->not->toContain('\\')
        ->and($stored->auditable_type)->not->toContain('App');
})->with([
    [MilkProduction::class, 'milk_production'],
    [MilkUsage::class, 'milk_usage'],
    [MilkAdjustment::class, 'milk_adjustment'],
]);

/*
|--------------------------------------------------------------------------
| C. Phase boundary
|--------------------------------------------------------------------------
*/

test('every Phase 3 route exists', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
})->with([
    'milk.production.index',
    'milk.production.store',
    'milk.usage.index',
    'milk.usage.store',
    'milk.usage.cancel',
    'milk.adjustments.index',
    'milk.adjustments.store',
    'milk.adjustments.cancel',
    'milk.reconciliation',
]);

test('the Customer Daily Entry routes exist as of Phase 4 Pass 2', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
})->with([
    'milk.customer-entry.index',
    'milk.customer-entry.store',
    'milk.customer-entry.copy-previous',
]);

test('the Phase 5 routes exist as of Phase 5', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
})->with([
    'mandalis.index',
    'mandalis.show',
    'mandalis.statement',
    'mandalis.settlements.index',
    'mandalis.settlements.finalize',
    'vendors.index',
    'vendors.show',
    'vendors.statement',
    // The custom-channel trade surface, added in Pass 2 so a receivable raised by the
    // generic sale form has somewhere to be seen and settled (D48).
    'other-buyers.index',
    'other-buyers.show',
    'other-buyers.statement',
    'milk.mandali-deliveries.index',
    'milk.mandali-deliveries.slip',
    'milk.vendor-sales.index',
    'milk.other-sales.index',
    'buyers.payments.store',
    'buyers.payments.cancel',
]);

test('no Phase 6 or later route exists yet', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->toBeNull();
})->with([
    // Phase 6 — animals
    'animals.index',
    'animals.create',
    'animals.events.store',
    // Phase 7 — employees and payroll
    'employees.index',
    'payroll.index',
    'employees.loans.store',
    // Phase 8 onwards
    'notifications.index',
    'reports.index',
]);

test('the Phase 4 Pass 1 customer routes exist', function (string $name) {
    expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
})->with([
    'customers.index',
    'customers.store',
    'customers.show',
    'customers.pauses.store',
    'customers.payments.store',
    'customers.prices.store',
]);

test('the Phase 4 and 5 tables exist and the Phase 6 ones do not', function (string $table, bool $exists) {
    expect(Schema::hasTable($table))->toBe($exists);
})->with([
    // Phase 4, built.
    ['milk_sales', true],
    ['customer_preferences', true],
    ['customer_pauses', true],
    ['buyer_payments', true],
    // Phase 5, built.
    ['buyer_settlements', true],
    ['buyer_balance_adjustments', true],
    // Phase 6 onwards, not yet.
    ['animals', false],
    ['animal_events', false],
    ['employees', false],
]);

test('one buyer table still serves every channel workflow', function () {
    /*
     * Phase 2 built `buyers.*` as master data; Phase 4 added a customer surface over
     * the same table and Phase 5 added Mandali and vendor surfaces. The guard's point
     * survives all three: there is still **one** buyer table, and the per-channel
     * screens are surfaces over it rather than parallel identities.
     */
    expect(app('router')->getRoutes()->getByName('buyers.index'))->not->toBeNull()
        ->and(Schema::hasTable('buyers'))->toBeTrue();

    foreach (['mandalis', 'vendors', 'other_buyers', 'customers_v2'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("A parallel {$table} identity table exists.");
    }

    // And one payment table, reached by every channel through the same route.
    expect(app('router')->getRoutes()->getByName('buyers.payments.store'))->not->toBeNull();

    foreach (['mandali_payments', 'vendor_payments', 'settlement_payments'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("A parallel {$table} table exists.");
    }
});

test('the sidebar advertises no unbuilt milk module', function () {
    $this->actingAs(superAdmin());

    $html = $this->get(route('milk.production.index'))->assertOk()->getContent();

    /*
     * Phase 4 Pass 2 built Customer Daily Entry, so the sidebar now links to it and
     * the guard checks what it was always really protecting: every link in the menu
     * goes to a route that exists, and nothing is advertised as forthcoming.
     */
    expect($html)->toContain('Customer Daily Entry')
        ->and($html)->toContain(route('milk.customer-entry.index'))
        ->and(strtolower($html))->not->toContain('coming soon');

    // Phase 5 built the three channel sale screens, so they are linked too.
    expect($html)->toContain(route('milk.mandali-deliveries.index'))
        ->and($html)->toContain(route('milk.vendor-sales.index'))
        ->and($html)->toContain(route('milk.other-sales.index'));

    // Phase 6 and later are still absent from the menu.
    expect($html)->not->toContain('Animals')
        ->and($html)->not->toContain('Payroll')
        ->and($html)->not->toContain('Employees');
});

test('every sidebar link resolves to a real route', function () {
    $this->actingAs(superAdmin());

    $html = $this->get(route('milk.production.index'))->assertOk()->getContent();

    preg_match_all('/<a class="nav-link[^"]*"\s+href="([^"]+)"/', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $href) {
        // A link the sidebar draws must answer, not 404.
        $this->get($href)->assertSuccessful();
    }
});
