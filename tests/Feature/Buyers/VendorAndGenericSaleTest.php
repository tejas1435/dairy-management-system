<?php

use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Actions\Milk\UpdateChannelSale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\MilkUsage;
use App\Models\SalesChannel;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Services\PriceResolver;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/*
 * Vendor sales and the generic form for custom channels.
 *
 * The interesting rule here is the difference between the two. A vendor has a
 * configured rate, so typing a different figure is a deliberate override that needs a
 * permission and a reason. A custom channel has no price rules at all, so typing the
 * rate is simply how it works — and treating that as an override would demand the
 * permission for ordinary daily entry.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->vendor = Buyer::factory()->vendor($this->business)->create(['name' => 'Patel Dairy']);
    $this->shop = Buyer::factory()->inCustomChannel($this->business)->create(['name' => 'Corner Sweet Shop']);

    $this->record = app(RecordChannelSale::class);
    $this->engine = app(CalculateMilkReconciliation::class);
    $this->outstanding = app(BuyerOutstandingService::class);

    $this->sell = fn (Buyer $buyer, SaleSource $source, ?string $rate, array $attributes = [], string $quantity = '10.000') => $this->record->handle(
        buyer: $buyer,
        source: $source,
        date: $this->date,
        shift: Shift::Morning,
        milkType: MilkType::Cow,
        quantity: $quantity,
        rate: $rate,
        attributes: $attributes,
    );

    // Everything except the override permission, which individual tests add.
    $this->operator = fn () => userWithPermissions([
        'milk.sale.view', 'milk.sale.create', 'milk.sale.update', 'milk.sale.cancel',
        'vendor.view', 'vendor.create', 'vendor.update',
    ]);
});

/*
|--------------------------------------------------------------------------
| A. Channel identity
|--------------------------------------------------------------------------
*/

test('a vendor and a custom-channel buyer are both just buyers', function () {
    expect($this->vendor->isVendor())->toBeTrue()
        ->and($this->vendor->isCustomChannel())->toBeFalse()
        ->and($this->shop->isCustomChannel())->toBeTrue()
        ->and($this->shop->isVendor())->toBeFalse()
        ->and($this->shop->isMandali())->toBeFalse()
        ->and($this->shop->isDirectCustomer())->toBeFalse();

    expect(Buyer::query()->vendors()->pluck('id')->all())->toBe([$this->vendor->id])
        ->and(Buyer::query()->customChannel()->pluck('id')->all())->toBe([$this->shop->id]);
});

/*
|--------------------------------------------------------------------------
| B. Vendor pricing: resolved by default
|--------------------------------------------------------------------------
*/

test('a vendor sale with no typed rate uses the configured rate', function () {
    $this->actingAs(($this->operator)());

    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, null);

    // The business default for cow.
    expectMoney($sale->unit_rate, '70.00');
    expectMoney($sale->amount, '700.00');

    expect($sale->hasRateOverride())->toBeFalse()
        ->and($sale->resolved_rate)->toBeNull();
});

test('a buyer-specific vendor rate wins over the business default', function () {
    BuyerPriceRule::factory()->for($this->vendor)->forType(MilkType::Cow)
        ->rate('74.00')->period('2026-10-01')->create();
    app(PriceResolver::class)->forget();

    $this->actingAs(($this->operator)());

    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, null);

    expectMoney($sale->unit_rate, '74.00');
    expect($sale->hasRateOverride())->toBeFalse();
});

test('retyping the configured rate is not an override', function () {
    $this->actingAs(($this->operator)());

    // The form shows 70.00 and the operator submits it back unchanged. Demanding the
    // override permission for that would be absurd.
    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, '70.00');

    expectMoney($sale->unit_rate, '70.00');
    expect($sale->hasRateOverride())->toBeFalse()
        ->and($sale->rate_override_reason)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| C. Vendor overrides need permission and a reason
|--------------------------------------------------------------------------
*/

test('an unauthorised user cannot depart from the configured rate', function () {
    $this->actingAs(($this->operator)());

    expect(fn () => ($this->sell)($this->vendor, SaleSource::VendorSale, '72.00', [
        'rate_override_reason' => 'Agreed over the phone',
    ]))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

test('an authorised override is stored as a snapshot with its provenance', function () {
    $this->actingAs(userWithPermissions([
        'milk.sale.view', 'milk.sale.create', 'milk.sale.override_rate', 'vendor.view',
    ]));

    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, '72.00', [
        'rate_override_reason' => 'Agreed over the phone for this load',
    ]);

    // The applied rate is the snapshot; the configured one is kept as history.
    expectMoney($sale->unit_rate, '72.00');
    expectMoney($sale->amount, '720.00');
    expectMoney($sale->resolved_rate, '70.00');

    expect($sale->hasRateOverride())->toBeTrue()
        ->and($sale->rate_override_reason)->toBe('Agreed over the phone for this load');
});

test('an override without a reason is refused even with the permission', function () {
    $this->actingAs(userWithPermissions([
        'milk.sale.view', 'milk.sale.create', 'milk.sale.override_rate', 'vendor.view',
    ]));

    expect(fn () => ($this->sell)($this->vendor, SaleSource::VendorSale, '72.00'))
        ->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

test('recording a rate where none is configured also needs the override permission', function () {
    // No cow price at all on this date.
    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $this->actingAs(($this->operator)());

    /*
     * "No rate configured" is also what a mistyped buyer looks like, so typing a
     * figure into that gap is a decision about money and needs the same permission.
     */
    expect(fn () => ($this->sell)($this->vendor, SaleSource::VendorSale, '72.00', [
        'rate_override_reason' => 'One-off sale',
    ]))->toThrow(ValidationException::class);

    $this->actingAs(userWithPermissions([
        'milk.sale.view', 'milk.sale.create', 'milk.sale.override_rate', 'vendor.view',
    ]));

    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, '72.00', [
        'rate_override_reason' => 'One-off sale, no standing rate',
    ]);

    expectMoney($sale->unit_rate, '72.00');
    // Null, which is itself the record: there was no rate to depart from.
    expect($sale->resolved_rate)->toBeNull()
        ->and($sale->rate_override_reason)->not->toBeEmpty();
});

test('a vendor sale with no rate and none configured is refused outright', function () {
    MilkPriceRule::query()->where('milk_type', MilkType::Cow->value)->delete();
    app(PriceResolver::class)->forget();

    $this->actingAs(superAdmin());

    expect(fn () => ($this->sell)($this->vendor, SaleSource::VendorSale, null))
        ->toThrow(ValidationException::class);
});

test('the customer delivery override permission does not authorise a vendor override', function () {
    /*
     * `milk.customer_delivery.override_rate` belongs to the daily entry grid and
     * stays deferred there. Borrowing it would make one permission mean two
     * capabilities in two screens.
     */
    $this->actingAs(userWithPermissions([
        'milk.sale.view', 'milk.sale.create', 'vendor.view',
        'milk.customer_delivery.override_rate',
    ]));

    expect(fn () => ($this->sell)($this->vendor, SaleSource::VendorSale, '72.00', [
        'rate_override_reason' => 'Trying the wrong permission',
    ]))->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D. The snapshot survives a quantity correction
|--------------------------------------------------------------------------
*/

test('correcting a vendor quantity keeps the rate it was sold at', function () {
    $this->actingAs(superAdmin());

    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, null);
    expectMoney($sale->unit_rate, '70.00');

    // A buyer rate is agreed afterwards, covering the same date.
    BuyerPriceRule::factory()->for($this->vendor)->forType(MilkType::Cow)
        ->rate('80.00')->period('2026-10-01')->create();
    app(PriceResolver::class)->forget();

    app(UpdateChannelSale::class)->handle($sale, ['quantity' => '5.000']);

    $sale->refresh();

    // D41: the row keeps its snapshot; only the quantity changed.
    expectMoney($sale->unit_rate, '70.00');
    expectMoney($sale->amount, '350.00');
});

test('changing the rate on a recorded sale needs permission and a reason', function () {
    $this->actingAs(superAdmin());
    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, null);

    // Without the permission.
    $this->actingAs(($this->operator)());

    expect(fn () => app(UpdateChannelSale::class)->handle($sale, [
        'unit_rate' => '75.00',
        'rate_override_reason' => 'Renegotiated',
    ]))->toThrow(ValidationException::class);

    expectMoney($sale->refresh()->unit_rate, '70.00');

    // With the permission but no reason.
    $this->actingAs(userWithPermissions([
        'milk.sale.view', 'milk.sale.update', 'milk.sale.override_rate', 'vendor.view',
    ]));

    expect(fn () => app(UpdateChannelSale::class)->handle($sale, ['unit_rate' => '75.00']))
        ->toThrow(ValidationException::class);

    // With both.
    app(UpdateChannelSale::class)->handle($sale, [
        'unit_rate' => '75.00',
        'rate_override_reason' => 'Renegotiated after delivery',
    ]);

    $sale->refresh();

    expectMoney($sale->unit_rate, '75.00');
    expectMoney($sale->amount, '750.00');
    expectMoney($sale->resolved_rate, '70.00');
    expect($sale->rate_override_reason)->toBe('Renegotiated after delivery');
});

test('re-submitting the same rate is not a rate change', function () {
    $this->actingAs(superAdmin());
    $sale = ($this->sell)($this->vendor, SaleSource::VendorSale, null);

    // No override permission, but nothing is actually changing.
    $this->actingAs(($this->operator)());

    app(UpdateChannelSale::class)->handle($sale, ['unit_rate' => '70.00', 'quantity' => '8.000']);

    $sale->refresh();

    expect(Quantity::of($sale->quantity))->toBe('8.000')
        ->and($sale->hasRateOverride())->toBeFalse();
    expectMoney($sale->amount, '560.00');
});

/*
|--------------------------------------------------------------------------
| E. The generic form
|--------------------------------------------------------------------------
*/

test('a custom-channel sale takes a typed rate with no override permission', function () {
    $this->actingAs(($this->operator)());

    // A custom channel has no price rules, so typing the rate is the workflow.
    $sale = ($this->sell)($this->shop, SaleSource::GenericSale, '90.00');

    expectMoney($sale->unit_rate, '90.00');
    expectMoney($sale->amount, '900.00');

    expect($sale->source)->toBe(SaleSource::GenericSale)
        ->and($sale->hasRateOverride())->toBeFalse()
        ->and($sale->sales_channel_id)->toBe($this->shop->sales_channel_id);
});

test('the generic form needs a rate', function () {
    $this->actingAs(($this->operator)());

    expect(fn () => ($this->sell)($this->shop, SaleSource::GenericSale, null))
        ->toThrow(ValidationException::class);
});

test('the generic form refuses every system channel', function (string $channel) {
    $this->actingAs(superAdmin());

    $buyer = match ($channel) {
        SalesChannel::MANDALI => Buyer::factory()->mandali($this->business)->create(),
        SalesChannel::VENDOR => Buyer::factory()->vendor($this->business)->create(),
        default => directCustomer($this->business, [MilkType::Cow]),
    };

    /*
     * Each of the three has its own workflow with its own rules — a pause schedule, a
     * settlement history, a fat reading. The generic form must not be a way round
     * them, and a select list filtered in the browser is not a control.
     */
    expect(fn () => ($this->sell)($buyer, SaleSource::GenericSale, '90.00'))
        ->toThrow(ValidationException::class);

    expect(MilkSale::query()->count())->toBe(0);
})->with([SalesChannel::MANDALI, SalesChannel::VENDOR, SalesChannel::DIRECT_CUSTOMER]);

test('the vendor workflow refuses a custom-channel buyer and vice versa', function () {
    $this->actingAs(superAdmin());

    expect(fn () => ($this->sell)($this->shop, SaleSource::VendorSale, null))
        ->toThrow(ValidationException::class);

    expect(fn () => ($this->sell)($this->vendor, SaleSource::GenericSale, '90.00'))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| F. Both feed one engine
|--------------------------------------------------------------------------
*/

test('every channel lands in its own reconciliation bucket, counted once', function () {
    $this->actingAs(superAdmin());

    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '30.000']);

    MilkUsage::factory()->for($this->farm)->create([
        'usage_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '2.000',
    ]);

    // Direct customers 10.000
    $customer = directCustomer($this->business, [MilkType::Cow]);
    app(SaveCustomerDailySale::class)
        ->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    // Mandali 8.000
    $mandali = Buyer::factory()->mandali($this->business)->create();
    ($this->sell)($mandali, SaleSource::MandaliDelivery, '72.00', ['fat_percentage' => '4.50'], '8.000');

    // Vendor 4.000
    ($this->sell)($this->vendor, SaleSource::VendorSale, null, [], '4.000');

    // Other 1.000
    ($this->sell)($this->shop, SaleSource::GenericSale, '90.00', [], '1.000');

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->forChannel(SalesChannel::DIRECT_CUSTOMER))->toBe('10.000')
        ->and($result->sales->forChannel(SalesChannel::MANDALI))->toBe('8.000')
        ->and($result->sales->forChannel(SalesChannel::VENDOR))->toBe('4.000')
        ->and($result->sales->total)->toBe('23.000')
        // 23.000 sold plus 2.000 used.
        ->and($result->allocated)->toBe('25.000')
        ->and($result->remaining)->toBe('5.000');

    // The custom channel is in the breakdown too, under its own slug.
    expect($result->sales->byChannel)->toHaveKey('sweet_shop');
    expect($result->sales->byChannel['sweet_shop'])->toBe('1.000');

    // And the parts equal the whole: no sale counted twice.
    $sum = Quantity::sum($result->sales->byChannel);
    expect($sum)->toBe('23.000');
});

test('a vendor and a generic sale each create a receivable and no cash entry', function () {
    $this->actingAs(superAdmin());

    ($this->sell)($this->vendor, SaleSource::VendorSale, null);
    ($this->sell)($this->shop, SaleSource::GenericSale, '90.00');

    expectMoney($this->outstanding->breakdownFor($this->vendor)['outstanding'], '700.00');
    expectMoney($this->outstanding->breakdownFor($this->shop)['outstanding'], '900.00');

    expect(FinancialLedgerEntry::query()->count())->toBe(0);
});

test('withdrawing a vendor or generic sale returns the milk and the receivable', function () {
    $this->actingAs(superAdmin());

    $vendorSale = ($this->sell)($this->vendor, SaleSource::VendorSale, null);
    $shopSale = ($this->sell)($this->shop, SaleSource::GenericSale, '90.00');

    foreach ([$vendorSale, $shopSale] as $sale) {
        app(CancelMilkSale::class)->handle($sale, 'Load returned');
    }

    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->total)->toBe('0.000')
        ->and($result->remaining)->toBe('100.000');

    expectMoney($this->outstanding->breakdownFor($this->vendor)['outstanding'], '0.00');
    expectMoney($this->outstanding->breakdownFor($this->shop)['outstanding'], '0.00');
});
