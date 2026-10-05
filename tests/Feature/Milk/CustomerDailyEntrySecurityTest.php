<?php

use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\Farm;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\SalesChannel;
use App\Support\Quantity;

/*
 * Hand-written payloads.
 *
 * Every protection the grid shows — a disabled field, an absent row, a closed
 * button — is a courtesy to the operator and nothing more. The screen is one
 * client; `curl` is another. Each case below bypasses the browser entirely and
 * must be refused on the server's own terms.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->actingAs(superAdmin());

    $this->post = fn (array $rows) => $this->postJson(
        route('milk.customer-entry.store'),
        ['date' => $this->date, 'rows' => $rows],
    );

    $this->row = fn (int $buyerId, string $type = 'cow', ?string $morning = '1.000'): array => [
        'buyer_id' => $buyerId,
        'milk_type' => $type,
        'morning' => $morning,
        'evening' => null,
    ];
});

/*
|--------------------------------------------------------------------------
| A. Buyers who do not belong in this workflow
|--------------------------------------------------------------------------
*/

test('a buyer from another channel cannot be given a customer delivery', function (string $slug) {
    $channel = SalesChannel::query()->where('slug', $slug)->firstOrFail();
    $buyer = Buyer::factory()->inChannel($channel)->create();

    ($this->post)([($this->row)($buyer->id)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
})->with([
    'mandali' => [SalesChannel::MANDALI],
    'vendor' => [SalesChannel::VENDOR],
]);

test('a buyer from another business cannot be reached', function () {
    $otherBusiness = Business::factory()->create();
    $otherChannel = SalesChannel::factory()->for($otherBusiness)->create([
        'slug' => SalesChannel::DIRECT_CUSTOMER,
    ]);
    $foreign = Buyer::factory()->inChannel($otherChannel)->create();

    ($this->post)([($this->row)($foreign->id)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a buyer id that does not exist is refused rather than ignored', function () {
    // Silently skipping it would tell the operator their entry was saved.
    ($this->post)([($this->row)(999999)])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| B. Customers who cannot take a delivery on this date
|--------------------------------------------------------------------------
*/

test('an archived customer is refused even though the grid never offered them', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    $customer->update(['is_active' => false]);

    ($this->post)([($this->row)($customer->id)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a customer who has not started yet is refused', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['start_date' => '2026-12-01']);

    ($this->post)([($this->row)($customer->id)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a paused customer is refused, disabled input or not', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(CreateCustomerPause::class)->handle($customer, '2026-10-01', '2026-10-31', 'Away');

    ($this->post)([($this->row)($customer->id)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a milk type the customer does not take is refused', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([($this->row)($customer->id, 'buffalo')])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a deactivated preference is refused', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    $customer->preferences()->update(['is_active' => false]);

    ($this->post)([($this->row)($customer->id)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. Malformed quantities
|--------------------------------------------------------------------------
*/

test('quantities outside the column contract are refused', function (mixed $value) {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([($this->row)($customer->id, 'cow', $value)])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
})->with([
    'negative' => ['-1.000'],
    'four decimals' => ['1.0001'],
    'beyond the column' => ['99999999.000'],
    'not a number' => ['abc'],
    'a comma decimal separator' => ['1,500'],
]);

/*
|--------------------------------------------------------------------------
| D. Fields the client does not get to decide
|--------------------------------------------------------------------------
*/

test('a forged rate does not reach the sale', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => '2.000',
        'evening' => null,
        'unit_rate' => '1.00',
        'rate' => '1.00',
    ]])->assertOk();

    expectMoney(MilkSale::query()->value('unit_rate'), '70.00');
    expectMoney(MilkSale::query()->value('amount'), '140.00');
});

test('a forged amount does not reach the sale', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => '1.000',
        'evening' => null,
        'amount' => '99999.00',
    ]])->assertOk();

    expectMoney(MilkSale::query()->value('amount'), '70.00');
});

test('a forged farm id does not move the sale to another farm', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    $otherFarm = Farm::factory()->for($this->business)->create();

    ($this->post)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => '1.000',
        'evening' => null,
        'farm_id' => $otherFarm->id,
    ]])->assertOk();

    expect(MilkSale::query()->value('farm_id'))->toBe($this->farm->id);
});

test('a forged source cannot smuggle a sale past the grid identity', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => '1.000',
        'evening' => null,
        'source' => 'mandali_collection',
    ]])->assertOk();

    expect(MilkSale::query()->firstOrFail()->source)->toBe(SaleSource::CustomerDailyGrid);

    // Which means re-saving still updates rather than creating a second row.
    ($this->post)([($this->row)($customer->id, 'cow', '2.000')])->assertOk();

    expect(MilkSale::query()->count())->toBe(1);
});

test('reminder quantities submitted as fields are not read as deliveries', function () {
    $customer = customerWithReminder($this->business, MilkType::Cow, '5.000', '6.000');

    ($this->post)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => null,
        'evening' => null,
        // A client trying to be helpful. It is not a quantity.
        'morning_reminder_qty' => '5.000',
        'evening_reminder_qty' => '6.000',
    ]])->assertOk();

    expect(MilkSale::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| E. Availability cannot be argued with
|--------------------------------------------------------------------------
*/

test('no payload field can authorise an over-allocation', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '1.000']);

    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([[
        'buyer_id' => $customer->id,
        'milk_type' => 'cow',
        'morning' => '5.000',
        'evening' => null,
        // None of these exist, and none of them would help if they did.
        'force' => true,
        'save_anyway' => true,
        'skip_availability' => true,
        'create_adjustment' => true,
    ]])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0)
        // And no adjustment was conjured to make room.
        ->and(MilkAdjustment::query()->count())->toBe(0);
});

test('a duplicate pair in one payload cannot be used to double-allocate', function () {
    MilkProduction::query()->where('shift', Shift::Morning->value)
        ->update(['cow_milk_quantity' => '5.000']);

    $customer = directCustomer($this->business, [MilkType::Cow]);

    ($this->post)([
        ($this->row)($customer->id, 'cow', '4.000'),
        ($this->row)($customer->id, 'cow', '4.000'),
    ])->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| F. Historical records are not rewritten behind the operator's back
|--------------------------------------------------------------------------
*/

test('a pause created later does not cancel or reprice a delivery already recorded', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(SaveCustomerDailySale::class)
        ->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    $before = MilkSale::query()->firstOrFail();

    // The pause arrives afterwards, covering a date that already has a delivery.
    app(CreateCustomerPause::class)->handle($customer, '2026-10-01', '2026-10-31', 'Away');

    // Opening the day must not touch it...
    $this->get(route('milk.customer-entry.index', ['date' => $this->date]))->assertOk();

    $after = MilkSale::query()->firstOrFail();

    expect($after->status)->toBe($before->status)
        ->and(Quantity::of($after->quantity))->toBe('2.000');
    expectMoney($after->unit_rate, '70.00');

    // ...and neither must re-saving the day with that row unchanged.
    ($this->post)([($this->row)($customer->id, 'cow', '2.000')])
        ->assertOk()
        ->assertJsonPath('summary.changed', 0);

    expect(Quantity::of(MilkSale::query()->value('quantity')))->toBe('2.000')
        ->and(MilkSale::query()->firstOrFail()->status)->toBe($before->status);
});

test('a paused date refuses a change to the historical delivery it covers', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    app(SaveCustomerDailySale::class)
        ->handle($customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    app(CreateCustomerPause::class)->handle($customer, '2026-10-01', '2026-10-31', 'Away');

    /*
     * The existing sale is preserved, but it cannot be edited through the grid
     * while the pause stands — the pause has to be withdrawn first, which is a
     * deliberate, audited act rather than something that happens silently from a
     * daily entry screen.
     */
    ($this->post)([($this->row)($customer->id, 'cow', '3.000')])->assertStatus(422);

    expect(Quantity::of(MilkSale::query()->value('quantity')))->toBe('2.000');
});

test('errors never leak a stack trace or SQL', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $body = ($this->post)([($this->row)($customer->id, 'cow', '-5.000')])
        ->assertStatus(422)
        ->getContent();

    expect($body)->not->toContain('SQLSTATE')
        ->not->toContain('vendor\\laravel')
        ->not->toContain('#0 ')
        ->not->toContain('select * from');
});
