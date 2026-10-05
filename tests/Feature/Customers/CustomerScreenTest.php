<?php

use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\CustomerPreference;

/*
 * The customer screens: the list, the profile and the form.
 *
 * What is asserted is the output a person reads — the derived outstanding figure, the
 * pause timeline, the reminders shown as reminders, and no raw translation keys.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '1000.00');
    $this->cash = $accounts['cash'];

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = now()->toDateString();

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date);

    $this->customer = directCustomer($this->business, [MilkType::Cow], [
        'name' => 'Rajesh Patel',
        'mobile' => '9820011001',
        'area' => 'Station Road',
        'payment_cycle' => 'monthly',
        'delivery_note' => 'Leave at the gate',
    ]);

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. The list
|--------------------------------------------------------------------------
*/

test('the customer list shows the operational columns', function () {
    $this->get(route('customers.index'))
        ->assertOk()
        ->assertSee('Rajesh Patel')
        ->assertSee('9820011001')
        ->assertSee('Station Road')
        ->assertSee(MilkType::Cow->label())
        ->assertSee(__('customers.list.columns.outstanding'));
});

test('the list shows the derived outstanding figure', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    $html = $this->get(route('customers.index'))->assertOk()->getContent();

    // 10.000 x 70.00 = 700.00
    expect($html)->toContain('700.00');
});

test('the list marks a customer paused today', function () {
    app(CreateCustomerPause::class)->handle($this->customer, $this->date, $this->date, 'Away');

    $this->get(route('customers.index'))
        ->assertOk()
        ->assertSee(__('customers.list.paused_today'));
});

test('the list marks an archived customer', function () {
    $this->customer->forceFill(['is_active' => false])->save();

    $this->get(route('customers.index'))
        ->assertOk()
        ->assertSee(__('customers.status.archived'));
});

test('the list paginates server-side', function () {
    // Thirty customers against a page size of twenty-five.
    collect(range(1, 30))->each(fn () => directCustomer($this->business, [MilkType::Cow]));

    $response = $this->get(route('customers.index'))->assertOk();

    $response->assertViewHas('customers', fn ($customers): bool => $customers->perPage() === 25
        && $customers->total() === 31
        && $customers->count() === 25);
});

test('the list filters by area, milk type and status', function () {
    $buffaloCustomer = directCustomer($this->business, [MilkType::Buffalo], [
        'name' => 'Harish Trivedi', 'area' => 'Market Lane',
    ]);

    $this->get(route('customers.index', ['area' => 'Station']))
        ->assertOk()->assertSee('Rajesh Patel')->assertDontSee('Harish Trivedi');

    $this->get(route('customers.index', ['milk_type' => MilkType::Buffalo->value]))
        ->assertOk()->assertSee('Harish Trivedi')->assertDontSee('Rajesh Patel');

    $this->customer->forceFill(['is_active' => false])->save();

    $this->get(route('customers.index', ['status' => 'archived']))
        ->assertOk()->assertSee('Rajesh Patel')->assertDontSee('Harish Trivedi');
});

test('an empty list explains itself', function () {
    $this->customer->delete();

    $this->get(route('customers.index'))->assertOk()->assertSee(__('customers.list.none_yet'));
});

/*
|--------------------------------------------------------------------------
| B. The profile
|--------------------------------------------------------------------------
*/

test('the profile shows identity, delivery note and payment cycle', function () {
    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee('Rajesh Patel')
        ->assertSee('Leave at the gate')
        ->assertSee('monthly')
        ->assertSee(__('customers.fields.delivery_note'));
});

test('the profile shows the outstanding breakdown with the adjustments term named', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '250.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $html = $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.outstanding.formula'))
        // The third term of the formula is present and explained rather than omitted.
        ->assertSee(__('customers.outstanding.adjustments'))
        ->assertSee(__('customers.outstanding.adjustments_note'))
        ->getContent();

    expect($html)->toContain('700.00')   // sales
        ->and($html)->toContain('250.00') // payments
        ->and($html)->toContain('450.00'); // outstanding
});

test('the profile keeps sales and payments apart, with the explanation', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.statement.sales'))
        ->assertSee(__('customers.statement.payments_received'))
        // Revenue recorded is not cash received.
        ->assertSee(__('customers.statement.not_cash'));
});

test('the profile shows reminders labelled as reminders', function () {
    CustomerPreference::query()->where('buyer_id', $this->customer->id)
        ->update(['morning_reminder_qty' => '1.000', 'evening_reminder_qty' => '2.000']);

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.fields.morning_reminder'))
        ->assertSee(__('customers.fields.evening_reminder'))
        // The rule, stated on the screen where somebody might misread the numbers.
        ->assertSee(__('customers.reminders.explanation'));
});

test('the profile warns when a customer has no milk preference', function () {
    $noPreference = directCustomer($this->business, []);

    $this->get(route('customers.show', $noPreference))
        ->assertOk()
        ->assertSee(__('customers.preferences.none'));
});

test('the profile shows the pause timeline', function () {
    app(CreateCustomerPause::class)->handle($this->customer, $this->date, $this->date, 'Family wedding');
    app(CreateCustomerPause::class)->handle($this->customer, '2026-12-01', '2026-12-10', 'Winter break');

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.pauses.current'))
        ->assertSee(__('customers.pauses.upcoming'))
        ->assertSee('Family wedding')
        ->assertSee('Winter break');
});

test('the profile shows why a customer is not deliverable', function () {
    app(CreateCustomerPause::class)->handle($this->customer, $this->date, $this->date, 'Away');

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.eligibility.paused'));
});

test('the profile shows the price override history and which rate applies', function () {
    app(SetMilkPrice::class)->forBuyer(
        buyer: $this->customer, milkType: MilkType::Cow,
        rate: '75.50', effectiveFrom: '2020-06-01',
    );

    $html = $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.prices.title'))
        ->assertSee(__('customers.prices.using_override'))
        ->getContent();

    expect($html)->toContain('75.50');
});

test('the profile shows the business default when there is no override', function () {
    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.prices.using_default'));
});

test('the profile ledger shows morning and evening side by side', function () {
    $sell = app(SaveCustomerDailySale::class);
    $sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.500');
    $sell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '2.500');

    $html = $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.ledger.morning'))
        ->assertSee(__('customers.ledger.evening'))
        ->assertSee(__('customers.ledger.balance'))
        ->getContent();

    expect($html)->toContain('1.500')
        ->and($html)->toContain('2.500')
        ->and($html)->toContain('4.000')
        // 4.000 x 70.00
        ->and($html)->toContain('280.00');
});

test('the profile ledger shows an empty state for a quiet period', function () {
    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.ledger.none'));
});

test('the profile statement respects a date filter', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    // A window that excludes the delivery.
    $this->get(route('customers.show', [$this->customer, 'from' => '2020-01-01', 'to' => '2020-01-31']))
        ->assertOk()
        ->assertSee(__('customers.ledger.none'));
});

test('the profile lists payments and shows a cancelled one as cancelled', function () {
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    $payment = app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id, 'reference' => 'Cheque 4411',
    ]);

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee('Cheque 4411');

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque bounced');

    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee(__('finance.statuses.cancelled'))
        ->assertSee('Cheque bounced');
});

/*
|--------------------------------------------------------------------------
| C. The form
|--------------------------------------------------------------------------
*/

test('the create form offers no sales channel field', function () {
    $this->get(route('customers.create'))
        ->assertOk()
        // The channel is assigned server-side; offering it would imply otherwise.
        ->assertDontSee('name="sales_channel_id"', false);
});

test('the create form has a checkbox and reminders per milk type', function () {
    $response = $this->get(route('customers.create'))->assertOk();

    foreach (MilkType::cases() as $milkType) {
        $response->assertSee('name="preferences['.$milkType->value.'][is_active]"', false)
            ->assertSee('name="preferences['.$milkType->value.'][morning]"', false)
            ->assertSee('name="preferences['.$milkType->value.'][evening]"', false);
    }
});

test('the edit form prefills the existing profile and preferences', function () {
    CustomerPreference::query()->where('buyer_id', $this->customer->id)
        ->update(['morning_reminder_qty' => '1.250']);

    $this->get(route('customers.edit', $this->customer))
        ->assertOk()
        ->assertSee('Rajesh Patel')
        ->assertSee('Leave at the gate')
        ->assertSee('1.250');
});

test('the edit form offers archiving to someone who may archive', function () {
    $this->get(route('customers.edit', $this->customer))
        ->assertOk()
        ->assertSee(__('customers.actions.archive'));
});

/*
|--------------------------------------------------------------------------
| D. Localisation
|--------------------------------------------------------------------------
*/

test('every customer screen renders in gujarati and hindi without a raw key', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();

    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    app(CreateCustomerPause::class)->handle($this->customer, '2026-12-01', '2026-12-05', 'Winter break');

    foreach ([
        route('customers.index'),
        route('customers.create'),
        route('customers.show', $this->customer),
        route('customers.edit', $this->customer),
    ] as $url) {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        // A missing key renders as its raw dotted path.
        expect($html)->not->toMatch('/\b(customers|milk|nav|app|pricing|finance)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');
    }
})->with(['gu', 'hi']);

test('the customer navigation is translated in all three locales', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    app()->setLocale($locale);

    $html = $this->actingAs($this->admin)->get(route('customers.index'))->assertOk()->getContent();

    expect($html)->toContain(__('nav.customers'))
        ->and($html)->toContain(__('nav.direct_customers'));

    app()->setLocale('en');
})->with(['en', 'gu', 'hi']);

test('the Gujarati and Hindi customer strings are genuinely translated', function (string $locale) {
    $keys = [
        'customers.title',
        'customers.fields.delivery_note',
        'customers.pauses.title',
        'customers.statement.outstanding',
        'customers.payments.record',
        'customers.reminders.explanation',
    ];

    foreach ($keys as $key) {
        app()->setLocale('en');
        $english = __($key);

        app()->setLocale($locale);
        $translated = __($key);

        expect($translated)->not->toBe($key)
            ->and($translated)->not->toBe($english);
    }

    app()->setLocale('en');
})->with(['gu', 'hi']);
