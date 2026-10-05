<?php

use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\CreateMilkSale;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\MilkAdjustment;
use App\Models\MilkSale;
use App\Models\MilkUsage;
use App\Models\SalesChannel;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * The canonical milk sale: rate snapshots, exact amounts, availability, idempotency
 * and cancellation.
 *
 * This is the foundation the Pass 2 daily entry grid will sit on, which is why it is
 * tested before the grid exists rather than after.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->customer = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);

    $this->engine = app(CalculateMilkReconciliation::class);
    $this->save = app(SaveCustomerDailySale::class);

    $this->cowMorning = fn () => $this->engine->forShift(
        $this->farm->id, $this->date, Shift::Morning, MilkType::Cow
    );

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. Schema: generic across channels
|--------------------------------------------------------------------------
*/

test('milk_sales carries the documented columns and types', function () {
    expect(Schema::getColumnType('milk_sales', 'sale_date'))->toBe('date')
        ->and(Schema::getColumnType('milk_sales', 'quantity'))->toBe('decimal')
        ->and(Schema::getColumnType('milk_sales', 'unit_rate'))->toBe('decimal')
        ->and(Schema::getColumnType('milk_sales', 'amount'))->toBe('decimal')
        ->and(Schema::getColumnType('milk_sales', 'shift'))->toBe('varchar')
        ->and(Schema::getColumnType('milk_sales', 'milk_type'))->toBe('varchar')
        ->and(Schema::getColumnType('milk_sales', 'source'))->toBe('varchar');
});

test('the money and quantity columns carry the documented precision', function (string $column, string $type) {
    $actual = DB::selectOne(
        'SELECT COLUMN_TYPE as column_type FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['milk_sales', $column]
    );

    expect(strtolower((string) $actual->column_type))->toBe($type);
})->with([
    ['quantity', 'decimal(10,3)'],
    ['unit_rate', 'decimal(10,2)'],
    ['amount', 'decimal(14,2)'],
]);

test('the fat and SNF columns exist, nullable and unused by Phase 4', function () {
    // Allocated here by the Phase 0 design because a Mandali delivery records them
    // against the sale. V1 has no fat/SNF pricing formula, so nothing reads them.
    expect(Schema::hasColumn('milk_sales', 'fat_percentage'))->toBeTrue()
        ->and(Schema::hasColumn('milk_sales', 'snf_percentage'))->toBeTrue();

    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    expect($sale->fat_percentage)->toBeNull()
        ->and($sale->snf_percentage)->toBeNull();
});

test('the source is a stable machine value from the enum', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    expect($sale->source)->toBe(SaleSource::CustomerDailyGrid)
        ->and(DB::table('milk_sales')->value('source'))->toBe('customer_daily_grid');
});

/*
|--------------------------------------------------------------------------
| B. Rate snapshot and exact amount
|--------------------------------------------------------------------------
*/

test('a sale snapshots the resolved rate and computes the amount server-side', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.500');

    expect($sale->unit_rate)->toBe('70.00')
        ->and($sale->quantity)->toBe('2.500')
        // 2.500 x 70.00 = 175.00, computed here rather than taken from a form.
        ->and($sale->amount)->toBe('175.00')
        ->and($sale->amount)->toBe($sale->expectedAmount());
});

test('a customer price override wins over the business default', function () {
    app(SetMilkPrice::class)->forBuyer(
        buyer: $this->customer,
        milkType: MilkType::Cow,
        rate: '75.50',
        effectiveFrom: '2026-10-01',
    );

    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    expect($sale->unit_rate)->toBe('75.50')
        ->and($sale->amount)->toBe('151.00');
});

test('a later price change never alters a past sale', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    expect($sale->unit_rate)->toBe('70.00');

    // A new period opening after the sale date.
    app(SetMilkPrice::class)->forBuyer(
        buyer: $this->customer,
        milkType: MilkType::Cow,
        rate: '99.00',
        effectiveFrom: '2026-11-01',
    );

    expect($sale->fresh()->unit_rate)->toBe('70.00')
        ->and($sale->fresh()->amount)->toBe('140.00');
});

test('resolution follows the sale date, not today', function () {
    // A rate that applies only from November.
    app(SetMilkPrice::class)->forBuyer(
        buyer: $this->customer,
        milkType: MilkType::Cow,
        rate: '99.00',
        effectiveFrom: '2026-11-01',
    );

    // A sale dated in October still gets October's rate.
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    expect($sale->unit_rate)->toBe('70.00');
});

test('the amount rounds half up rather than truncating', function () {
    app(SetMilkPrice::class)->forBuyer(
        buyer: $this->customer, milkType: MilkType::Cow, rate: '85.00', effectiveFrom: '2026-10-01',
    );

    // 3.333 x 85.00 = 283.30500 exactly, which must become 283.31 and not 283.30.
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.333');

    expect($sale->amount)->toBe('283.31')
        ->and(Quantity::multiplyToMoney('3.333', '85.00'))->toBe('283.31');
});

test('a missing price is an explicit refusal, never a zero-rate sale', function () {
    // Withdraw both the override and the default by using a buyer whose business has
    // no price for buffalo at all.
    DB::table('milk_price_rules')->where('milk_type', MilkType::Buffalo->value)->delete();

    expect(fn () => $this->save->handle(
        $this->customer, $this->date, Shift::Morning, MilkType::Buffalo, '1.000'
    ))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a buffalo sale uses the buffalo rate, not the cow one', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Buffalo, '2.000');

    expect($sale->unit_rate)->toBe('85.00')
        ->and($sale->amount)->toBe('170.00');
});

/*
|--------------------------------------------------------------------------
| C. Idempotency: one row per customer, date, shift and milk type
|--------------------------------------------------------------------------
*/

test('saving the same cell twice updates one row rather than adding another', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');

    expect(MilkSale::query()->count())->toBe(1);

    $sale = MilkSale::query()->firstOrFail();

    expect($sale->quantity)->toBe('3.000')
        ->and($sale->amount)->toBe('210.00');
});

test('the database rejects a duplicate grid row inserted behind the application', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    expect(fn () => DB::table('milk_sales')->insert([
        'farm_id' => $sale->farm_id,
        'sales_channel_id' => $sale->sales_channel_id,
        'buyer_id' => $sale->buyer_id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '1.000',
        'unit_rate' => '70.00',
        'amount' => '70.00',
        'source' => SaleSource::CustomerDailyGrid->value,
        'status' => TransactionStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(MilkSale::query()->count())->toBe(1);
});

test('the generated key is the grid identity and is null for other sources', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    $key = DB::table('milk_sales')->value('daily_grid_key');

    expect($key)->toBe(implode('|', [
        $this->farm->id, $this->customer->id, $this->date, 'morning', 'cow',
    ]));

    /*
     * A different source gets a NULL key, so it is not held to the one-row identity.
     * That is what lets a Phase 5 generic sale form record two vendor sales in one
     * shift without the grid's constraint getting in the way.
     */
    DB::table('milk_sales')->insert([
        'farm_id' => $this->farm->id,
        'sales_channel_id' => $this->customer->sales_channel_id,
        'buyer_id' => $this->customer->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '1.000', 'unit_rate' => '70.00', 'amount' => '70.00',
        'source' => 'some_future_source',
        'status' => TransactionStatus::Active->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('milk_sales')->whereNull('daily_grid_key')->count())->toBe(1);
});

test('different shifts, milk types, dates and customers are separate rows', function () {
    $other = directCustomer($this->business, [MilkType::Cow]);
    seedProductionFor($this->farm, '2026-10-11');

    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');
    $this->save->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '1.000');
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Buffalo, '1.000');
    $this->save->handle($this->customer, '2026-10-11', Shift::Morning, MilkType::Cow, '1.000');
    $this->save->handle($other, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    expect(MilkSale::query()->count())->toBe(5);
});

test('saving a whole day for one milk type touches both shifts', function () {
    $saved = $this->save->forDay($this->customer, $this->date, MilkType::Cow, [
        'morning' => '1.500',
        'evening' => '2.500',
    ]);

    expect(MilkSale::query()->count())->toBe(2)
        ->and($saved['morning']->quantity)->toBe('1.500')
        ->and($saved['evening']->quantity)->toBe('2.500');
});

/*
|--------------------------------------------------------------------------
| D. Clearing a quantity cancels rather than deletes
|--------------------------------------------------------------------------
*/

test('clearing a quantity cancels the sale and keeps the row', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    $result = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, null);

    expect($result)->toBeNull()
        ->and(MilkSale::query()->count())->toBe(1);

    $sale->refresh();

    expect($sale->status)->toBe(TransactionStatus::Cancelled)
        ->and($sale->cancelled_by)->toBe($this->admin->id)
        // The quantity and amount are untouched; only the effect stops.
        ->and($sale->quantity)->toBe('2.000')
        ->and($sale->cancellation_reason)
        ->toBe(__('customers.sale_cancellation.removed_from_customer_daily_entry'));
});

test('a zero quantity is treated as removal, not as a zero sale', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '0');

    expect(MilkSale::query()->active()->count())->toBe(0)
        ->and(MilkSale::query()->count())->toBe(1);
});

test('clearing an already empty cell does nothing at all', function () {
    $result = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, null);

    expect($result)->toBeNull()
        ->and(MilkSale::query()->count())->toBe(0);
});

test('re-entering a quantity after removal reactivates the row', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, null);

    $revived = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.500');

    expect($revived->id)->toBe($sale->id)
        ->and($revived->status)->toBe(TransactionStatus::Active)
        ->and($revived->quantity)->toBe('1.500')
        ->and($revived->cancelled_at)->toBeNull()
        ->and($revived->cancellation_reason)->toBeNull()
        // Still one row: the unique identity holds.
        ->and(MilkSale::query()->count())->toBe(1);

    // The withdrawal and the re-entry both survive in the audit trail.
    $actions = AuditLog::query()->where('auditable_type', 'milk_sale')
        ->orderBy('id')->pluck('action')->map(fn ($a) => $a->value)->all();

    expect($actions)->toBe(['created', 'cancelled', 'updated']);
});

test('a cancelled sale leaves the reconciliation and the outstanding balance', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '4.000');

    expect(($this->cowMorning)()->sales->total)->toBe('4.000');

    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, null);

    expect(($this->cowMorning)()->sales->total)->toBe('0.000')
        ->and(app(BuyerOutstandingService::class)
            ->outstandingFor($this->customer))->toBe('0.00');
});

test('no route hard-deletes a milk sale', function () {
    /*
     * Matched on route name rather than the URI: `settings/sales-channels/{id}` has a
     * DELETE verb and the word "sale" in its path, and it is the channel master
     * rather than anything to do with a delivery.
     */
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true))
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->values();

    foreach ($names as $name) {
        expect($name)->not->toContain('milk.sales')
            ->and($name)->not->toContain('customer-entry');
    }
});

/*
|--------------------------------------------------------------------------
| E. Availability — the Phase 3 rule, inherited
|--------------------------------------------------------------------------
*/

test('a sale beyond the available milk is refused', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '10.000', buffalo: '0.000');

    expect(fn () => $this->save->handle(
        $this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '10.001'
    ))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

test('exactly the available milk is allowed', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '10.000', buffalo: '0.000');

    $sale = $this->save->handle($this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '10.000');

    expect($sale->quantity)->toBe('10.000')
        ->and($this->engine->forShift($this->farm->id, '2026-10-12', Shift::Morning, MilkType::Cow)->remaining)
        ->toBe('0.000');
});

test('sales and internal usage compete for the same milk', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '10.000', buffalo: '0.000');

    MilkUsage::factory()->for($this->farm)->on('2026-10-12')
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('8.000')->create();

    // 2.000 left, so 2.001 must fail and 2.000 must pass.
    expect(fn () => $this->save->handle(
        $this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '2.001'
    ))->toThrow(ValidationException::class);

    $this->save->handle($this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '2.000');

    expect($this->engine->forShift($this->farm->id, '2026-10-12', Shift::Morning, MilkType::Cow)->remaining)
        ->toBe('0.000');
});

test('increasing an existing sale only needs the difference to be available', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '10.000', buffalo: '0.000');

    $this->save->handle($this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '8.000');

    // 2.000 free, and the row's own 8.000 is discounted, so 10.000 fits...
    $this->save->handle($this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '10.000');

    expect(MilkSale::query()->firstOrFail()->quantity)->toBe('10.000');

    // ...but 10.001 does not.
    expect(fn () => $this->save->handle(
        $this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '10.001'
    ))->toThrow(ValidationException::class);

    expect(MilkSale::query()->firstOrFail()->quantity)->toBe('10.000');
});

test('a sale against a shift with no production entered is refused', function () {
    // No production at all for this date: not zero milk, but no statement.
    expect(fn () => $this->save->handle(
        $this->customer, '2026-10-20', Shift::Morning, MilkType::Cow, '1.000'
    ))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a refused sale creates no compensating adjustment', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '1.000', buffalo: '0.000');

    expect(fn () => $this->save->handle(
        $this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '50.000'
    ))->toThrow(ValidationException::class);

    // No sale, and emphatically no adjustment invented to make room.
    expect(MilkSale::query()->count())->toBe(0)
        ->and(MilkAdjustment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| F. Reconciliation integration — Enter Once, Update Everywhere
|--------------------------------------------------------------------------
*/

test('a customer sale appears in the milk reconciliation with no second entry', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '10.000', buffalo: '0.000');

    MilkUsage::factory()->for($this->farm)->on('2026-10-12')
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('1.000')->create();

    $this->save->handle($this->customer, '2026-10-12', Shift::Morning, MilkType::Cow, '4.000');

    $result = $this->engine->forShift($this->farm->id, '2026-10-12', Shift::Morning, MilkType::Cow);

    // production 10.000, usage 1.000, sales 4.000 -> remaining 5.000
    expect($result->production)->toBe('10.000')
        ->and($result->usageTotal)->toBe('1.000')
        ->and($result->sales->total)->toBe('4.000')
        ->and($result->allocated)->toBe('5.000')
        ->and($result->remaining)->toBe('5.000');
});

test('the reconciliation attributes the sale to the direct customer channel', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');

    $result = ($this->cowMorning)();

    expect($result->sales->forChannel('direct_customer'))->toBe('3.000')
        ->and($result->sales->forChannel('mandali'))->toBe('0.000')
        ->and($result->sales->forChannel('vendor'))->toBe('0.000')
        // No Mandali or vendor sale is fabricated.
        ->and($result->sales->total)->toBe('3.000');
});

test('sales of one milk type do not leak into the other', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Buffalo, '2.000');

    expect(($this->cowMorning)()->sales->total)->toBe('3.000')
        ->and($this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Buffalo)->sales->total)
        ->toBe('2.000');
});

/*
|--------------------------------------------------------------------------
| G. Paused, archived and unstarted customers are refused on the server
|--------------------------------------------------------------------------
*/

test('a paused customer cannot be given a delivery', function () {
    app(CreateCustomerPause::class)->handle($this->customer, $this->date, $this->date, 'Away');

    expect(fn () => $this->save->handle(
        $this->customer->fresh(), $this->date, Shift::Morning, MilkType::Cow, '1.000'
    ))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

test('an archived customer cannot be given a delivery', function () {
    $this->customer->forceFill(['is_active' => false])->save();

    expect(fn () => $this->save->handle(
        $this->customer->fresh(), $this->date, Shift::Morning, MilkType::Cow, '1.000'
    ))->toThrow(ValidationException::class);
});

test('a customer cannot be given a delivery before their start date', function () {
    $this->customer->forceFill(['start_date' => '2026-11-01'])->save();

    expect(fn () => $this->save->handle(
        $this->customer->fresh(), $this->date, Shift::Morning, MilkType::Cow, '1.000'
    ))->toThrow(ValidationException::class);
});

test('a milk type the customer does not take is refused', function () {
    $cowOnly = directCustomer($this->business, [MilkType::Cow]);

    expect(fn () => $this->save->handle(
        $cowOnly, $this->date, Shift::Morning, MilkType::Buffalo, '1.000'
    ))->toThrow(ValidationException::class);
});

test('a non-customer buyer cannot be given a grid delivery', function () {
    $mandali = Buyer::factory()
        ->inChannel(SalesChannel::query()->where('slug', 'mandali')->firstOrFail())
        ->create();

    expect(fn () => $this->save->handle($mandali, $this->date, Shift::Morning, MilkType::Cow, '1.000'))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| H. The generic action, for Phase 5
|--------------------------------------------------------------------------
*/

test('the generic CreateMilkSale records a sale for any buyer with a channel', function () {
    $sale = app(CreateMilkSale::class)->handle(
        buyer: $this->customer,
        date: $this->date,
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: '2.000',
    );

    expect($sale->quantity)->toBe('2.000')
        ->and($sale->unit_rate)->toBe('70.00')
        ->and($sale->amount)->toBe('140.00')
        ->and($sale->sales_channel_id)->toBe($this->customer->sales_channel_id);
});

test('the generic action accepts an explicit rate, for a channel that sets its own', function () {
    // Phase 5's Mandali enters a manual rate rather than resolving one.
    $sale = app(CreateMilkSale::class)->handle(
        buyer: $this->customer,
        date: $this->date,
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: '2.000',
        rate: '64.25',
    );

    expect($sale->unit_rate)->toBe('64.25')
        ->and($sale->amount)->toBe('128.50');
});

test('the generic action enforces availability too', function () {
    seedProductionFor($this->farm, '2026-10-12', cow: '1.000', buffalo: '0.000');

    expect(fn () => app(CreateMilkSale::class)->handle(
        buyer: $this->customer, date: '2026-10-12', shift: Shift::Morning,
        milkType: MilkType::Cow, quantity: '2.000',
    ))->toThrow(ValidationException::class);
});

test('the generic action refuses an archived buyer', function () {
    $this->customer->forceFill(['is_active' => false])->save();

    expect(fn () => app(CreateMilkSale::class)->handle(
        buyer: $this->customer->fresh(), date: $this->date, shift: Shift::Morning,
        milkType: MilkType::Cow, quantity: '1.000',
    ))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| I. Copy Previous Day foundation — the query only
|--------------------------------------------------------------------------
*/

test('previous day quantities can be read, keyed by milk type and shift', function () {
    seedProductionFor($this->farm, '2026-10-09');

    $this->save->handle($this->customer, '2026-10-09', Shift::Morning, MilkType::Cow, '1.500');
    $this->save->handle($this->customer, '2026-10-09', Shift::Evening, MilkType::Cow, '2.500');
    $this->save->handle($this->customer, '2026-10-09', Shift::Morning, MilkType::Buffalo, '0.750');

    $previous = $this->save->previousDayQuantities($this->customer, '2026-10-10');

    expect($previous)->toBe([
        'cow' => ['morning' => '1.500', 'evening' => '2.500'],
        'buffalo' => ['morning' => '0.750'],
    ]);
});

test('a cancelled sale is not offered for copying', function () {
    seedProductionFor($this->farm, '2026-10-09');

    $this->save->handle($this->customer, '2026-10-09', Shift::Morning, MilkType::Cow, '1.500');
    $this->save->handle($this->customer, '2026-10-09', Shift::Morning, MilkType::Cow, null);

    expect($this->save->previousDayQuantities($this->customer, '2026-10-10'))->toBe([]);
});

test('reading yesterday does not create anything', function () {
    seedProductionFor($this->farm, '2026-10-09');
    $this->save->handle($this->customer, '2026-10-09', Shift::Morning, MilkType::Cow, '1.500');

    $before = MilkSale::query()->count();

    $this->save->previousDayQuantities($this->customer, '2026-10-10');

    // Copy Previous Day is an explicit action in Pass 2. Nothing here copies
    // automatically, and no observer does either.
    expect(MilkSale::query()->count())->toBe($before)
        ->and(MilkSale::query()->whereDate('sale_date', '2026-10-10')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| J. Audit
|--------------------------------------------------------------------------
*/

test('creating a sale is audited with the quantity, rate and amount', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    $log = AuditLog::query()->where('auditable_type', 'milk_sale')->latest('id')->firstOrFail();

    expect($log->action->value)->toBe('created')
        ->and($log->new_values['quantity'])->toBe('2.000')
        ->and($log->new_values['unit_rate'])->toBe('70.00')
        ->and($log->new_values['amount'])->toBe('140.00')
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->subject)->toContain($this->customer->name);
});

test('changing a quantity is audited with the old and new figures', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '3.000');

    $log = AuditLog::query()->where('auditable_type', 'milk_sale')
        ->where('action', 'updated')->latest('id')->firstOrFail();

    expect($log->old_values['quantity'])->toBe('2.000')
        ->and($log->new_values['quantity'])->toBe('3.000')
        ->and($log->old_values['amount'])->toBe('140.00')
        ->and($log->new_values['amount'])->toBe('210.00');
});

test('cancelling a sale is audited with its reason and actor', function () {
    $sale = $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    app(CancelMilkSale::class)->handle($sale, 'Delivered to the wrong address');

    $log = AuditLog::query()->where('auditable_type', 'milk_sale')
        ->where('action', 'cancelled')->latest('id')->firstOrFail();

    expect($log->new_values['cancellation_reason'])->toBe('Delivered to the wrong address')
        ->and($log->user_id)->toBe($this->admin->id);
});

test('an unchanged re-save writes no audit noise', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    $after = AuditLog::query()->where('auditable_type', 'milk_sale')->count();

    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    expect(AuditLog::query()->where('auditable_type', 'milk_sale')->count())->toBe($after);
});

test('the audit type is a stable alias, not a class name', function () {
    $this->save->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.000');

    $stored = DB::table('audit_logs')->where('auditable_type', 'milk_sale')->first();

    expect($stored)->not->toBeNull()
        ->and($stored->auditable_type)->not->toContain('\\')
        ->and($stored->auditable_type)->not->toContain('App');
});
