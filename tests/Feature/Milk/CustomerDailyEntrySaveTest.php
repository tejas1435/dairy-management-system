<?php

use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\BuyerPriceRule;
use App\Models\Farm;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Services\PriceResolver;
use App\Support\Quantity;

/*
 * Save Day: the bulk JSON write.
 *
 * This is where a page of typed quantities becomes money. The cases below are the
 * ones where being wrong would be both plausible and expensive — double-counting an
 * existing allocation, re-pricing a recorded sale, leaving half a day saved, or
 * treating a cleared field as a zero-rupee delivery.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->engine = app(CalculateMilkReconciliation::class);
    $this->cell = app(SaveCustomerDailySale::class);

    $this->actingAs(superAdmin());

    $this->save = fn (array $rows, ?string $date = null) => $this->postJson(
        route('milk.customer-entry.store'),
        ['date' => $date ?? $this->date, 'rows' => $rows],
    );

    $this->row = fn (int $buyerId, string $milkType, ?string $morning, ?string $evening): array => [
        'buyer_id' => $buyerId,
        'milk_type' => $milkType,
        'morning' => $morning,
        'evening' => $evening,
    ];

    $this->remaining = fn (Shift $shift, MilkType $type) => $this->engine
        ->forShift($this->farm->id, $this->date, $shift, $type)->remaining;
});

/*
|--------------------------------------------------------------------------
| A. The request is JSON, and the payload contract is narrow
|--------------------------------------------------------------------------
*/

test('the day saves from a JSON body', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', '2.000')])
        ->assertOk()
        ->assertJsonPath('saved', true)
        ->assertJsonPath('summary.created', 2);

    expect(MilkSale::query()->active()->count())->toBe(2);
});

test('the save route is not a form post that max_input_vars could truncate', function () {
    /*
     * D5 in one assertion: the controller reads a JSON body. A traditional form
     * post of two hundred customers exceeds the default max_input_vars and PHP
     * drops the tail silently, which would report success over a half-saved day.
     */
    $source = file_get_contents(resource_path('js/customer-daily-entry.js'));

    expect($source)->toContain("'Content-Type': 'application/json'")
        ->toContain('JSON.stringify(this.payload())')
        ->toContain("'X-CSRF-TOKEN'")
        // No jQuery, no Axios: the stack is vanilla ES modules.
        ->not->toContain('axios')
        ->not->toContain('jQuery');
});

test('posted amounts, rates, farms and channels are ignored entirely', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    $otherFarm = Farm::factory()->for($this->business)->create();

    ($this->save)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => '2.000',
        'evening' => null,
        // Everything below is a lie the server must not read.
        'amount' => '1.00',
        'unit_rate' => '1.00',
        'farm_id' => $otherFarm->id,
        'sales_channel_id' => 9999,
        'source' => 'mandali_collection',
        'status' => 'cancelled',
        'created_by' => 9999,
    ]])->assertOk();

    $sale = MilkSale::query()->active()->firstOrFail();

    expect($sale->farm_id)->toBe($this->farm->id)
        ->and($sale->sales_channel_id)->toBe($customer->sales_channel_id)
        ->and($sale->source)->toBe(SaleSource::CustomerDailyGrid)
        ->and($sale->status)->toBe(TransactionStatus::Active);

    expectMoney($sale->unit_rate, '70.00');
    expectMoney($sale->amount, '140.00');
});

test('a quantity with four decimal places is refused rather than rounded', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.4567', null)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rows.0.morning');

    expect(MilkSale::query()->count())->toBe(0);
});

test('a negative quantity is refused', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '-1.000', null)])
        ->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('two payload rows for the same customer and milk type are refused, not merged', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    // Otherwise the result would depend on which one happened to be applied last.
    ($this->save)([
        ($this->row)($customer->id, 'cow', '1.000', null),
        ($this->row)($customer->id, 'cow', '5.000', null),
    ])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('an unknown milk type is refused', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'goat', '1.000', null)])->assertStatus(422);
});

test('an empty day is a legitimate save that changes nothing', function () {
    ($this->save)([])->assertOk()->assertJsonPath('summary.changed', 0);
});

/*
|--------------------------------------------------------------------------
| B. Create, update, cancel, reactivate
|--------------------------------------------------------------------------
*/

test('a quantity where there was none creates one sale per shift', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', '2.500')])->assertOk();

    $sales = MilkSale::query()->active()->orderBy('shift')->get();

    expect($sales)->toHaveCount(2);
    expectMoney($sales->firstWhere('shift', Shift::Morning)->amount, '105.00');
    expectMoney($sales->firstWhere('shift', Shift::Evening)->amount, '175.00');
});

test('a different quantity updates the existing row rather than adding one', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', null)])->assertOk();
    ($this->save)([($this->row)($customer->id, 'cow', '2.000', null)])
        ->assertOk()
        ->assertJsonPath('summary.updated', 1);

    $sales = MilkSale::query()->get();

    expect($sales)->toHaveCount(1);
    expect(Quantity::of($sales->first()->quantity))->toBe('2.000');
    expectMoney($sales->first()->amount, '140.00');
});

test('the same quantity again is a no-op and writes no audit record', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', null)])->assertOk();

    $auditCount = AuditLog::query()->count();

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', null)])
        ->assertOk()
        ->assertJsonPath('summary.changed', 0);

    expect(AuditLog::query()->count())->toBe($auditCount);
});

test('clearing a quantity cancels the sale rather than deleting it', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', null)])->assertOk();
    ($this->save)([($this->row)($customer->id, 'cow', null, null)])
        ->assertOk()
        ->assertJsonPath('summary.cancelled', 1);

    $sale = MilkSale::query()->firstOrFail();

    expect($sale->status)->toBe(TransactionStatus::Cancelled)
        ->and($sale->cancellation_reason)->not->toBeEmpty()
        // The quantity stays on the row; it simply stops counting.
        ->and(Quantity::of($sale->quantity))->toBe('1.500');

    expect(MilkSale::query()->active()->count())->toBe(0);
});

test('blank, zero and 0.000 all mean no delivery and none of them create a row', function (?string $value) {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', $value, null)])->assertOk();

    // No zero-quantity sale, and therefore no zero-rupee receivable.
    expect(MilkSale::query()->count())->toBe(0);
})->with([
    'blank' => [null],
    'empty string' => [''],
    'zero' => ['0'],
    'zero to three places' => ['0.000'],
]);

test('zero against an existing sale cancels it, exactly as blank does', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', null)])->assertOk();
    ($this->save)([($this->row)($customer->id, 'cow', '0.000', null)])->assertOk();

    expect(MilkSale::query()->active()->count())->toBe(0)
        ->and(MilkSale::query()->count())->toBe(1);
});

test('re-entering a quantity revives the same row rather than creating a second', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.500', null)])->assertOk();
    $saleId = MilkSale::query()->value('id');

    ($this->save)([($this->row)($customer->id, 'cow', null, null)])->assertOk();
    ($this->save)([($this->row)($customer->id, 'cow', '1.250', null)])->assertOk();

    $sales = MilkSale::query()->get();

    expect($sales)->toHaveCount(1)
        ->and($sales->first()->id)->toBe($saleId)
        ->and($sales->first()->status)->toBe(TransactionStatus::Active)
        ->and($sales->first()->cancelled_at)->toBeNull();

    expect(Quantity::of($sales->first()->quantity))->toBe('1.250');

    // The withdrawal and the re-entry both survive in the audit trail.
    expect(AuditLog::query()->where('auditable_type', 'milk_sale')->count())
        ->toBeGreaterThanOrEqual(3);
});

/*
|--------------------------------------------------------------------------
| C. Availability on update — the invariant that is easy to get wrong
|--------------------------------------------------------------------------
*/

test('increasing an existing sale is measured against the final allocation, not the current remainder', function () {
    /*
     * Available 10.000. Another allocation holds 5.000 and this customer holds
     * 4.000, so only 1.000 is unallocated. Raising this customer's 4.000 to 5.000
     * must succeed, because the final total is exactly 10.000 — the old 4.000 is
     * being replaced, not added to.
     */
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $other = directCustomer($this->business, [MilkType::Cow]);
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($other, $this->date, Shift::Morning, MilkType::Cow, '5.000');
    $this->cell->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '4.000');

    expect(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('1.000');

    ($this->save)([($this->row)($customer->id, 'cow', '5.000', null)])->assertOk();

    expect(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('0.000');
});

test('the boundary is exact to the millilitre on an update', function (string $to, bool $allowed) {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $other = directCustomer($this->business, [MilkType::Cow]);
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($other, $this->date, Shift::Morning, MilkType::Cow, '5.000');
    $this->cell->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '4.000');

    $response = ($this->save)([($this->row)($customer->id, 'cow', $to, null)]);

    $allowed ? $response->assertOk() : $response->assertStatus(422);

    $sale = MilkSale::query()->where('buyer_id', $customer->id)->firstOrFail();

    expect(Quantity::of($sale->quantity))->toBe($allowed ? Quantity::of($to) : '4.000');
})->with([
    'down to 3.000' => ['3.000', true],
    'up to 5.000, exactly the limit' => ['5.000', true],
    'up to 5.001, one millilitre over' => ['5.001', false],
]);

test('clearing a full allocation returns the milk to the shift', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $customer = directCustomer($this->business, [MilkType::Cow]);
    $this->cell->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '4.000');

    ($this->save)([($this->row)($customer->id, 'cow', null, null)])->assertOk();

    expect(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('10.000');
});

/*
|--------------------------------------------------------------------------
| D. The whole day shares one shift's milk
|--------------------------------------------------------------------------
*/

test('several customers can fill a shift exactly, and one millilitre more fails the whole day', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);
    $c = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([
        ($this->row)($a->id, 'cow', '4.000', null),
        ($this->row)($b->id, 'cow', '4.000', null),
        ($this->row)($c->id, 'cow', '2.000', null),
    ])->assertOk();

    expect(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('0.000');

    // Now ask for 2.001 instead of 2.000 — the day as a whole no longer fits.
    ($this->save)([
        ($this->row)($a->id, 'cow', '4.000', null),
        ($this->row)($b->id, 'cow', '4.000', null),
        ($this->row)($c->id, 'cow', '2.001', null),
    ])->assertStatus(422);

    expect(Quantity::of(MilkSale::query()->where('buyer_id', $c->id)->value('quantity')))
        ->toBe('2.000');
});

test('moving milk from one customer to another in one save succeeds whatever the row order', function (bool $reversed) {
    /*
     * The shift is fully allocated and the operator moves 2.000 litres between two
     * customers. Processing the increase before the decrease would fail on milk
     * that is about to be freed in the same request, so releases are applied first
     * — and the payload order must not matter.
     */
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $from = directCustomer($this->business, [MilkType::Cow]);
    $to = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($from, $this->date, Shift::Morning, MilkType::Cow, '6.000');
    $this->cell->handle($to, $this->date, Shift::Morning, MilkType::Cow, '4.000');

    expect(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('0.000');

    $rows = [
        ($this->row)($from->id, 'cow', '4.000', null),
        ($this->row)($to->id, 'cow', '6.000', null),
    ];

    ($this->save)($reversed ? array_reverse($rows) : $rows)->assertOk();

    expect(Quantity::of(MilkSale::query()->where('buyer_id', $from->id)->value('quantity')))->toBe('4.000')
        ->and(Quantity::of(MilkSale::query()->where('buyer_id', $to->id)->value('quantity')))->toBe('6.000')
        ->and(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('0.000');
})->with(['increase first' => [true], 'decrease first' => [false]]);

test('a cancellation in the same request frees milk for another customer', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $leaving = directCustomer($this->business, [MilkType::Cow]);
    $growing = directCustomer($this->business, [MilkType::Cow]);

    $this->cell->handle($leaving, $this->date, Shift::Morning, MilkType::Cow, '6.000');
    $this->cell->handle($growing, $this->date, Shift::Morning, MilkType::Cow, '4.000');

    ($this->save)([
        ($this->row)($growing->id, 'cow', '10.000', null),
        ($this->row)($leaving->id, 'cow', null, null),
    ])->assertOk();

    expect(MilkSale::query()->active()->count())->toBe(1)
        ->and(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('0.000');
});

test('cow and buffalo do not borrow from each other', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)->update([
        'cow_milk_quantity' => '2.000',
        'buffalo_milk_quantity' => '10.000',
    ]);

    $customer = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);

    ($this->save)([
        ($this->row)($customer->id, 'cow', '3.000', null),
        ($this->row)($customer->id, 'buffalo', '1.000', null),
    ])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| E. Missing production is not a zero
|--------------------------------------------------------------------------
*/

test('a delivery against unentered production fails for that reason', function () {
    MilkProduction::query()->delete();

    $customer = directCustomer($this->business, [MilkType::Cow]);

    $response = ($this->save)([($this->row)($customer->id, 'cow', '1.000', null)])->assertStatus(422);

    expect(json_encode($response->json()))->toContain(__('milk.errors.production_not_entered_for_allocation', [
        'shift' => Shift::Morning->label(),
        'type' => MilkType::Cow->label(),
    ]));
});

test('a delivery against a recorded zero fails for insufficient milk, not for missing production', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '0.000']);

    $customer = directCustomer($this->business, [MilkType::Cow]);

    $response = ($this->save)([($this->row)($customer->id, 'cow', '1.000', null)])->assertStatus(422);

    // Production was entered; there simply is none left.
    $body = json_encode($response->json());

    expect($body)->not->toContain(__('milk.errors.production_not_entered_for_allocation', [
        'shift' => Shift::Morning->label(),
        'type' => MilkType::Cow->label(),
    ]));
});

/*
|--------------------------------------------------------------------------
| F. Rate snapshots survive a quantity edit
|--------------------------------------------------------------------------
*/

test('editing a quantity keeps the rate the sale was recorded at', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '2.000', null)])->assertOk();

    /*
     * A buyer override is added afterwards covering the same date. Re-resolving on
     * update would re-price a delivery that may already have been billed, simply
     * because somebody reopened the day to fix the litres.
     */
    BuyerPriceRule::factory()->for($customer)->forType(MilkType::Cow)
        ->rate('99.00')->period('2026-10-01')->create();
    app(PriceResolver::class)->forget();

    ($this->save)([($this->row)($customer->id, 'cow', '3.000', null)])->assertOk();

    $sale = MilkSale::query()->firstOrFail();

    expectMoney($sale->unit_rate, '70.00');
    expectMoney($sale->amount, '210.00');
});

test('a new sale on the same day does use the current resolution', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '2.000', null)])->assertOk();

    BuyerPriceRule::factory()->for($customer)->forType(MilkType::Cow)
        ->rate('99.00')->period('2026-10-01')->create();
    app(PriceResolver::class)->forget();

    // The evening cell is new, so it is priced at what applies to the sale date now.
    ($this->save)([($this->row)($customer->id, 'cow', '2.000', '1.000')])->assertOk();

    $evening = MilkSale::query()->where('shift', Shift::Evening->value)->firstOrFail();

    expectMoney($evening->unit_rate, '99.00');
    expectMoney($evening->amount, '99.00');
});

test('a new sale cannot be created without a price', function () {
    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();

    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '1.000', null)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('an existing sale can still be corrected and cleared after its price is withdrawn', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([($this->row)($customer->id, 'cow', '2.000', null)])->assertOk();

    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    // Its own snapshot is enough to re-cost it.
    ($this->save)([($this->row)($customer->id, 'cow', '1.000', null)])->assertOk();

    expectMoney(MilkSale::query()->value('amount'), '70.00');

    ($this->save)([($this->row)($customer->id, 'cow', null, null)])->assertOk();

    expect(MilkSale::query()->active()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| G. Idempotency
|--------------------------------------------------------------------------
*/

test('saving the same day twice changes nothing the second time', function () {
    $a = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);
    $b = directCustomer($this->business, [MilkType::Cow]);

    $payload = [
        ($this->row)($a->id, 'cow', '1.500', '2.000'),
        ($this->row)($a->id, 'buffalo', '1.000', null),
        ($this->row)($b->id, 'cow', '3.000', '1.000'),
    ];

    ($this->save)($payload)->assertOk()->assertJsonPath('summary.changed', 5);

    $after = [
        'sales' => MilkSale::query()->count(),
        'active' => MilkSale::query()->active()->count(),
        'audits' => AuditLog::query()->count(),
        'remaining' => ($this->remaining)(Shift::Morning, MilkType::Cow),
    ];

    ($this->save)($payload)->assertOk()->assertJsonPath('summary.changed', 0);

    expect(MilkSale::query()->count())->toBe($after['sales'])
        ->and(MilkSale::query()->active()->count())->toBe($after['active'])
        ->and(AuditLog::query()->count())->toBe($after['audits'])
        ->and(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe($after['remaining']);
});

test('changing one cell of a saved day changes only that cell', function () {
    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([
        ($this->row)($a->id, 'cow', '1.000', '1.000'),
        ($this->row)($b->id, 'cow', '2.000', '2.000'),
    ])->assertOk();

    $untouched = MilkSale::query()->where('buyer_id', $b->id)->orderBy('shift')->pluck('updated_at', 'id');

    ($this->save)([
        ($this->row)($a->id, 'cow', '1.500', '1.000'),
        ($this->row)($b->id, 'cow', '2.000', '2.000'),
    ])->assertOk()->assertJsonPath('summary.changed', 1);

    foreach ($untouched as $id => $timestamp) {
        expect(MilkSale::query()->whereKey($id)->value('updated_at')->toDateTimeString())
            ->toBe($timestamp->toDateTimeString());
    }
});

/*
|--------------------------------------------------------------------------
| H. All or nothing
|--------------------------------------------------------------------------
*/

test('one bad cell rolls back every good cell in the same save', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);
    $c = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([
        ($this->row)($a->id, 'cow', '4.000', null),
        ($this->row)($b->id, 'cow', '4.000', null),
        // 12.000 in total against 10.000 available.
        ($this->row)($c->id, 'cow', '4.000', null),
    ])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_sale')->count())->toBe(0)
        ->and(($this->remaining)(Shift::Morning, MilkType::Cow))->toBe('10.000');
});

test('a failure partway through leaves earlier edits in the same request untouched', function () {
    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);

    ($this->save)([
        ($this->row)($a->id, 'cow', '1.000', null),
        ($this->row)($b->id, 'cow', '1.000', null),
    ])->assertOk();

    $before = MilkSale::query()->orderBy('buyer_id')->pluck('quantity', 'buyer_id')->toArray();
    $audits = AuditLog::query()->count();

    // The second row is for a customer that does not exist, so the whole save dies
    // after the first would otherwise have been applied.
    ($this->save)([
        ($this->row)($a->id, 'cow', '5.000', null),
        ($this->row)(999999, 'cow', '1.000', null),
    ])->assertStatus(422);

    expect(MilkSale::query()->orderBy('buyer_id')->pluck('quantity', 'buyer_id')->toArray())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($audits);
});

/*
|--------------------------------------------------------------------------
| I. The response is authoritative
|--------------------------------------------------------------------------
*/

test('the response returns the server figures the browser should display', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $response = ($this->save)([($this->row)($customer->id, 'cow', '3.333', null)])->assertOk();

    // 3.333 x 70.00 = 233.31, rounded half-up by the server.
    $response->assertJsonPath('totals.quantity', '3.333')
        ->assertJsonPath('totals.shifts.morning.cow', '3.333')
        ->assertJsonPath('availability.morning.cow.production_entered', true);

    expectMoney($response->json('totals.amount'), '233.31');

    expect($response->json('rows.0.cells.morning.saved'))->toBe('3.333')
        ->and($response->json('rows.0.cells.morning.ratePaise'))->toBe(7000);
});

test('the half-up rounding the browser is told to use matches the server', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    // 3.333 L at 85.00 is exactly 283.30500, which must become 283.31.
    seedDefaultPrices($this->business, cow: '85.00', buffalo: '85.00');
    app(PriceResolver::class)->forget();

    ($this->save)([($this->row)($customer->id, 'cow', '3.333', null)])->assertOk();

    expectMoney(MilkSale::query()->value('amount'), '283.31');
});
