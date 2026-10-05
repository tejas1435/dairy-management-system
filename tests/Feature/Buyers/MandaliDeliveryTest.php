<?php

use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\UpdateChannelSale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\MilkUsage;
use App\Models\SalesChannel;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/*
 * Mandali deliveries.
 *
 * The rule this file exists for is the one MASTER_SPEC section 22 states twice: the
 * rate is entered by hand, and fat and SNF are reference data. There is no fat/SNF
 * pricing formula in V1, and the test named after that rule is deliberately hard to
 * delete by accident.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);

    $this->record = app(RecordChannelSale::class);
    $this->engine = app(CalculateMilkReconciliation::class);

    $this->actingAs(superAdmin());

    $this->deliver = fn (string $quantity, string $rate, array $attributes = []) => $this->record->handle(
        buyer: $this->mandali,
        source: SaleSource::MandaliDelivery,
        date: $this->date,
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: $quantity,
        rate: $rate,
        attributes: $attributes,
    );
});

/*
|--------------------------------------------------------------------------
| A. The Mandali is a buyer, not a new kind of record
|--------------------------------------------------------------------------
*/

test('a Mandali is a buyer in the Mandali channel, with no table of its own', function () {
    foreach (['mandalis', 'vendors', 'other_buyers', 'customers_v2'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect($this->mandali->isMandali())->toBeTrue()
        ->and($this->mandali->isVendor())->toBeFalse()
        ->and($this->mandali->isDirectCustomer())->toBeFalse()
        ->and($this->mandali->isCustomChannel())->toBeFalse()
        ->and($this->mandali->channelSlug())->toBe(SalesChannel::MANDALI);
});

test('the Mandali scope returns only Mandali buyers', function () {
    Buyer::factory()->vendor($this->business)->create();
    directCustomer($this->business, [MilkType::Cow]);

    expect(Buyer::query()->mandalis()->pluck('id')->all())->toBe([$this->mandali->id]);
});

/*
|--------------------------------------------------------------------------
| B. THE rule: fat and SNF never price anything
|--------------------------------------------------------------------------
*/

test('mandali fat and snf never calculate the rate in v1', function () {
    /*
     * The regression test for MASTER_SPEC section 22. Two deliveries, same quantity
     * and same manually entered rate, wildly different quality readings. The amount
     * must be identical, because the amount is quantity x rate and fat and SNF are
     * recorded for reference and reporting only.
     *
     * If this test ever fails, somebody has introduced a quality-based pricing
     * formula — which V1 does not have and was explicitly told not to build.
     */
    $lowQuality = ($this->deliver)('10.000', '72.00', ['fat_percentage' => '3.10', 'snf_percentage' => '8.00']);

    $highQuality = app(RecordChannelSale::class)->handle(
        buyer: $this->mandali,
        source: SaleSource::MandaliDelivery,
        date: $this->date,
        shift: Shift::Evening,
        milkType: MilkType::Cow,
        quantity: '10.000',
        rate: '72.00',
        attributes: ['fat_percentage' => '6.90', 'snf_percentage' => '9.80'],
    );

    // Identical money, from identical quantity and rate.
    expectMoney($lowQuality->amount, '720.00');
    expectMoney($highQuality->amount, '720.00');
    expectMoney($lowQuality->amount, Quantity::money($highQuality->amount));

    // And the readings really were different and really were stored.
    expect(Quantity::money($lowQuality->fat_percentage))->toBe('3.10')
        ->and(Quantity::money($highQuality->fat_percentage))->toBe('6.90')
        ->and(Quantity::money($lowQuality->snf_percentage))->toBe('8.00')
        ->and(Quantity::money($highQuality->snf_percentage))->toBe('9.80');

    // The rate stored is the rate typed, untouched by either reading.
    expectMoney($lowQuality->unit_rate, '72.00');
    expectMoney($highQuality->unit_rate, '72.00');
});

test('changing fat and snf on a recorded delivery does not change its amount', function () {
    $sale = ($this->deliver)('10.000', '72.00', ['fat_percentage' => '4.50', 'snf_percentage' => '8.50']);

    expectMoney($sale->amount, '720.00');

    app(UpdateChannelSale::class)->handle($sale, [
        'fat_percentage' => '3.20',
        'snf_percentage' => '9.10',
    ]);

    $sale->refresh();

    expect(Quantity::money($sale->fat_percentage))->toBe('3.20')
        ->and(Quantity::money($sale->snf_percentage))->toBe('9.10');

    // Untouched.
    expectMoney($sale->amount, '720.00');
    expectMoney($sale->unit_rate, '72.00');
});

test('no pricing code reads a quality reading', function () {
    // A source guard, because this is the rule most likely to be "improved" later.
    $sources = [
        app_path('Actions/Milk/CreateMilkSale.php'),
        app_path('Actions/Milk/RecordChannelSale.php'),
        app_path('Actions/Milk/UpdateChannelSale.php'),
        app_path('Support/Quantity.php'),
        app_path('Services/PriceResolver.php'),
    ];

    foreach ($sources as $path) {
        $source = file_get_contents($path);

        // No arithmetic anywhere near the readings.
        expect($source)->not->toMatch('/fat_percentage[^;\n]*[*\/+]/')
            ->and($source)->not->toMatch('/multiplyToMoney\([^)]*fat/')
            ->and($source)->not->toMatch('/snf[^;\n]*multiplyToMoney/');
    }
});

/*
|--------------------------------------------------------------------------
| C. The manual rate
|--------------------------------------------------------------------------
*/

test('the amount is quantity times the manually entered rate', function () {
    $sale = ($this->deliver)('10.000', '72.00', ['fat_percentage' => '4.50']);

    expectMoney($sale->amount, '720.00');
    expectMoney($sale->amount, $sale->expectedAmount());
});

test('the manual rate ignores the configured business price', function () {
    // Cow is configured at 70.00; the Mandali rate is agreed at 72.00.
    $sale = ($this->deliver)('10.000', '72.00', ['fat_percentage' => '4.50']);

    expectMoney($sale->unit_rate, '72.00');

    // And it is not recorded as an override, because typing it is the workflow.
    expect($sale->hasRateOverride())->toBeFalse()
        ->and($sale->resolved_rate)->toBeNull()
        ->and($sale->rate_override_reason)->toBeNull();
});

test('a Mandali delivery needs a rate and refuses a zero one', function (?string $rate) {
    expect(fn () => ($this->deliver)('10.000', (string) $rate, ['fat_percentage' => '4.50']))
        ->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
})->with(['empty' => [''], 'zero' => ['0.00']]);

test('the amount is exact at three decimal litres', function () {
    // 3.333 L at 72.00 is 239.976, which rounds half-up to 239.98.
    $sale = ($this->deliver)('3.333', '72.00', ['fat_percentage' => '4.50']);

    expectMoney($sale->amount, '239.98');
});

test('a posted amount is never trusted', function () {
    $sale = ($this->deliver)('10.000', '72.00', [
        'fat_percentage' => '4.50',
        // Not a parameter the action accepts; proven by the stored figure.
        'amount' => '1.00',
    ]);

    expectMoney($sale->amount, '720.00');
});

/*
|--------------------------------------------------------------------------
| D. Server-side identity and provenance
|--------------------------------------------------------------------------
*/

test('the source, channel and farm are resolved on the server', function () {
    $sale = ($this->deliver)('10.000', '72.00', ['fat_percentage' => '4.50']);

    expect($sale->source)->toBe(SaleSource::MandaliDelivery)
        ->and($sale->sales_channel_id)->toBe($this->mandali->sales_channel_id)
        ->and($sale->farm_id)->toBe($this->farm->id);
});

test('a vendor or customer cannot be given a Mandali delivery', function (string $state) {
    $buyer = $state === 'customer'
        ? directCustomer($this->business, [MilkType::Cow])
        : Buyer::factory()->vendor($this->business)->create();

    expect(fn () => $this->record->handle(
        buyer: $buyer,
        source: SaleSource::MandaliDelivery,
        date: $this->date,
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: '5.000',
        rate: '72.00',
        attributes: ['fat_percentage' => '4.50'],
    ))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
})->with(['customer', 'vendor']);

test('several deliveries to one Mandali in one shift are allowed', function () {
    /*
     * Unlike the customer daily grid, a Mandali has no one-row-per-shift identity:
     * two collection trips in one morning are a real thing, and the grid's
     * uniqueness rule was written for a screen that re-saves a whole day. The
     * generated key is null for this source, so the database does not constrain it
     * (D38).
     */
    ($this->deliver)('10.000', '72.00', ['fat_percentage' => '4.50']);
    ($this->deliver)('8.000', '72.00', ['fat_percentage' => '4.40']);

    expect(MilkSale::query()->active()->count())->toBe(2);

    foreach (MilkSale::query()->get() as $sale) {
        expect($sale->daily_grid_key)->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| E. It allocates real milk
|--------------------------------------------------------------------------
*/

test('a Mandali delivery allocates milk and reaches reconciliation', function () {
    ($this->deliver)('12.000', '72.00', ['fat_percentage' => '4.50']);

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->total)->toBe('12.000')
        ->and($result->sales->forChannel(SalesChannel::MANDALI))->toBe('12.000')
        ->and($result->sales->forChannel(SalesChannel::DIRECT_CUSTOMER))->toBe('0.000')
        ->and($result->allocated)->toBe('12.000')
        ->and($result->remaining)->toBe('88.000');
});

test('a Mandali delivery cannot take milk the shift does not have', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    expect(fn () => ($this->deliver)('10.001', '72.00', ['fat_percentage' => '4.50']))
        ->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0)
        // No adjustment was conjured to make room.
        ->and(MilkAdjustment::query()->count())->toBe(0);
});

test('unentered production is reported as unentered, not as zero', function () {
    MilkProduction::query()->delete();

    try {
        ($this->deliver)('1.000', '72.00', ['fat_percentage' => '4.50']);
        $this->fail('Expected the delivery to be refused.');
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))->toContain(__('milk.errors.production_not_entered_for_allocation', [
            'shift' => Shift::Morning->label(),
            'type' => MilkType::Cow->label(),
        ]));
    }
});

test('raising a delivery does not double-count its own allocation', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '10.000']);

    $sale = ($this->deliver)('4.000', '72.00', ['fat_percentage' => '4.50']);

    // Another allocation takes 5.000, leaving 1.000 free.
    MilkUsage::factory()->for($this->farm)->create([
        'usage_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '5.000',
    ]);

    // 4.000 -> 5.000 fits exactly, because the old 4.000 is being replaced.
    app(UpdateChannelSale::class)->handle($sale, ['quantity' => '5.000']);

    expect(Quantity::of($sale->refresh()->quantity))->toBe('5.000');
    expectMoney($sale->amount, '360.00');

    // One millilitre more does not.
    expect(fn () => app(UpdateChannelSale::class)->handle($sale, ['quantity' => '5.001']))
        ->toThrow(ValidationException::class);

    expect(Quantity::of($sale->refresh()->quantity))->toBe('5.000');
});

/*
|--------------------------------------------------------------------------
| F. Money reaches the buyer, not the bank
|--------------------------------------------------------------------------
*/

test('a Mandali delivery creates a receivable and no ledger entry', function () {
    ($this->deliver)('10.000', '72.00', ['fat_percentage' => '4.50']);

    expectMoney(app(BuyerOutstandingService::class)->breakdownFor($this->mandali)['outstanding'], '720.00');

    // The Mandali owes money; none has moved. A sale that credited an account would
    // show cash the business does not have.
    expect(FinancialLedgerEntry::query()->count())->toBe(0);
});

test('withdrawing a delivery returns the milk and the receivable', function () {
    $sale = ($this->deliver)('12.000', '72.00', ['fat_percentage' => '4.50']);

    app(CancelMilkSale::class)->handle($sale, 'Recorded against the wrong Mandali');

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->total)->toBe('0.000')
        ->and($result->remaining)->toBe('100.000');

    expectMoney(app(BuyerOutstandingService::class)->breakdownFor($this->mandali)['outstanding'], '0.00');

    // History survives.
    $sale->refresh();

    expect($sale->status)->toBe(TransactionStatus::Cancelled)
        ->and($sale->cancellation_reason)->toBe('Recorded against the wrong Mandali')
        ->and(Quantity::of($sale->quantity))->toBe('12.000');
});

/*
|--------------------------------------------------------------------------
| H. The boundaries of what may be recorded
|--------------------------------------------------------------------------
*/

test('a quality reading outside the plausible range is refused', function (string $field, string $value) {
    $this->post(route('milk.mandali-deliveries.store'), [
        'buyer_id' => $this->mandali->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '10.000',
        'unit_rate' => '72.00',
        'fat_percentage' => '4.50',
        $field => $value,
    ])->assertSessionHasErrors($field);

    expect(MilkSale::query()->count())->toBe(0);
})->with([
    'fat above the maximum' => ['fat_percentage', '15.01'],
    'fat far above it' => ['fat_percentage', '45.00'],
    'negative fat' => ['fat_percentage', '-1.00'],
    'fat at three decimals' => ['fat_percentage', '4.505'],
    'fat that is not a number' => ['fat_percentage', 'four'],
    'snf above the maximum' => ['snf_percentage', '15.01'],
    'negative snf' => ['snf_percentage', '-0.01'],
    'snf at three decimals' => ['snf_percentage', '8.505'],
]);

test('the ends of the quality range are accepted', function (string $fat, string $snf) {
    $this->post(route('milk.mandali-deliveries.store'), [
        'buyer_id' => $this->mandali->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '10.000',
        'unit_rate' => '72.00',
        'fat_percentage' => $fat,
        'snf_percentage' => $snf,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $sale = MilkSale::query()->latest('id')->firstOrFail();

    // Stored as given and, as ever, not involved in the amount.
    expect((string) $sale->fat_percentage)->toBe($fat);
    expectMoney($sale->amount, '720.00');
})->with([
    'zero' => ['0.00', '0.00'],
    'the maximum' => ['15.00', '15.00'],
]);

test('a delivery is refused without the fat reading the dairy will compare against', function () {
    $this->post(route('milk.mandali-deliveries.store'), [
        'buyer_id' => $this->mandali->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '10.000',
        'unit_rate' => '72.00',
    ])->assertSessionHasErrors('fat_percentage');

    // SNF is optional, because not every collection point measures it.
    $this->post(route('milk.mandali-deliveries.store'), [
        'buyer_id' => $this->mandali->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '10.000',
        'unit_rate' => '72.00',
        'fat_percentage' => '4.50',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(MilkSale::query()->latest('id')->firstOrFail()->snf_percentage)->toBeNull();
});

test('a delivery may take the whole shift and not one thousandth more', function () {
    // 100.000 L produced this shift, 60.000 already gone.
    ($this->deliver)('60.000', '72.00', ['fat_percentage' => '4.50']);

    expect(fn () => ($this->deliver)('40.001', '72.00', ['fat_percentage' => '4.50']))
        ->toThrow(ValidationException::class);

    ($this->deliver)('40.000', '72.00', ['fat_percentage' => '4.50']);

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->remaining)->toBe('0.000')
        ->and($result->sales->total)->toBe('100.000');

    // And nothing more fits, however small.
    expect(fn () => ($this->deliver)('0.001', '72.00', ['fat_percentage' => '4.50']))
        ->toThrow(ValidationException::class);
});

test('a correction may raise a delivery to the whole shift and not past it', function () {
    $sale = ($this->deliver)('60.000', '72.00', ['fat_percentage' => '4.50']);

    expect(fn () => app(UpdateChannelSale::class)->handle($sale, ['quantity' => '100.001']))
        ->toThrow(ValidationException::class);

    app(UpdateChannelSale::class)->handle($sale, ['quantity' => '100.000']);

    expectMoney($sale->refresh()->quantity, '100.000');
    // 100.000 × 72.00, at the rate it was sold at.
    expectMoney($sale->amount, '7200.00');
});
