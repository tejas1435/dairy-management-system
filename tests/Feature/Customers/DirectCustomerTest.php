<?php

use App\Actions\Customers\SaveDirectCustomer;
use App\Enums\MilkType;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\CustomerPause;
use App\Models\CustomerPreference;
use App\Models\MilkSale;
use App\Models\SalesChannel;
use App\Services\Customers\CustomerEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * The direct customer identity, its channel safety, and milk preferences.
 *
 * The thing to get right is that a direct customer **is a buyer**, not a second
 * identity table — and that the workflows which assume customer-shaped data refuse a
 * Mandali or a vendor rather than quietly operating on one.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->admin = superAdmin();
    $this->channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $this->mandali = SalesChannel::query()->where('slug', SalesChannel::MANDALI)->firstOrFail();

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. A customer is a buyer, not a duplicate identity
|--------------------------------------------------------------------------
*/

test('there is no separate customers table', function () {
    // A direct customer is a row in `buyers`. A parallel identity table would mean
    // two places holding a name and a mobile, free to disagree.
    expect(Schema::hasTable('customers'))->toBeFalse()
        ->and(Schema::hasTable('direct_customers'))->toBeFalse();
});

test('customer identity fields live on buyers and are not duplicated elsewhere', function () {
    /*
     * `is_active` is deliberately absent from this list. It exists on both tables and
     * means two different things: on `buyers` the customer is on the round at all, on
     * `customer_preferences` they currently take that one milk type. That is two
     * facts, not one fact stored twice.
     */
    foreach (['name', 'mobile', 'email', 'address', 'area', 'payment_cycle', 'delivery_note'] as $column) {
        expect(Schema::hasColumn('buyers', $column))->toBeTrue();
        expect(Schema::hasColumn('customer_preferences', $column))->toBeFalse();
        expect(Schema::hasColumn('customer_pauses', $column))->toBeFalse();
    }
});

test('the two Phase 4 buyer columns exist with the documented names', function () {
    expect(Schema::hasColumn('buyers', 'delivery_note'))->toBeTrue()
        ->and(Schema::hasColumn('buyers', 'start_date'))->toBeTrue()
        ->and(Schema::getColumnType('buyers', 'start_date'))->toBe('date');
});

test('a direct customer is identified by its channel slug, not a flag', function () {
    $customer = directCustomer($this->business);
    $mandali = Buyer::factory()->inChannel($this->mandali)->create();

    expect($customer->isDirectCustomer())->toBeTrue()
        ->and($mandali->isDirectCustomer())->toBeFalse()
        // And no boolean column pretends to answer the same question.
        ->and(Schema::hasColumn('buyers', 'is_direct_customer'))->toBeFalse();
});

test('the directCustomers scope returns only direct customers', function () {
    directCustomer($this->business);
    directCustomer($this->business);
    Buyer::factory()->inChannel($this->mandali)->create();

    expect(Buyer::query()->directCustomers()->count())->toBe(2)
        ->and(Buyer::query()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| B. Channel safety
|--------------------------------------------------------------------------
*/

test('a Mandali cannot be opened in the customer profile workflow', function () {
    $mandali = Buyer::factory()->inChannel($this->mandali)->create();

    $this->get(route('customers.show', $mandali))->assertSessionHasErrors('buyer');
    $this->get(route('customers.edit', $mandali))->assertSessionHasErrors('buyer');
});

test('a Mandali cannot be given customer preferences through the customer screen', function () {
    $mandali = Buyer::factory()->inChannel($this->mandali)->create();

    $this->put(route('customers.update', $mandali), [
        'name' => $mandali->name,
        'preferences' => ['cow' => ['is_active' => 1, 'morning' => '1.000', 'evening' => '1.000']],
    ])->assertSessionHasErrors('buyer');

    expect(CustomerPreference::query()->count())->toBe(0);
});

test('the action itself refuses a non-customer, not only the controller', function () {
    $mandali = Buyer::factory()->inChannel($this->mandali)->create();

    expect(fn () => app(SaveDirectCustomer::class)->update($mandali, ['name' => 'Renamed']))
        ->toThrow(ValidationException::class);

    expect($mandali->fresh()->name)->not->toBe('Renamed');
});

test('a vendor cannot be paused through the customer screen', function () {
    $vendorChannel = SalesChannel::query()->where('slug', SalesChannel::VENDOR)->firstOrFail();
    $vendor = Buyer::factory()->inChannel($vendorChannel)->create();

    $this->post(route('customers.pauses.store', $vendor), [
        'start_date' => '2026-10-01',
    ])->assertSessionHasErrors('buyer');

    expect(CustomerPause::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. Creation assigns the channel server-side
|--------------------------------------------------------------------------
*/

test('creating a customer assigns the direct customer channel', function () {
    $this->post(route('customers.store'), [
        'name' => 'Rajesh Patel',
        'mobile' => '9820011001',
        'area' => 'Station Road',
    ])->assertRedirect();

    $customer = Buyer::query()->where('name', 'Rajesh Patel')->firstOrFail();

    expect($customer->sales_channel_id)->toBe($this->channel->id)
        ->and($customer->isDirectCustomer())->toBeTrue()
        ->and($customer->business_id)->toBe($this->business->id)
        ->and($customer->is_active)->toBeTrue();
});

test('a posted sales channel cannot redirect a new customer into another channel', function () {
    // The channel is assigned, not accepted. Otherwise this screen would be a way to
    // create a Mandali with customer permissions.
    $this->post(route('customers.store'), [
        'name' => 'Sneaky Mandali',
        'sales_channel_id' => $this->mandali->id,
    ])->assertRedirect();

    $customer = Buyer::query()->where('name', 'Sneaky Mandali')->firstOrFail();

    expect($customer->sales_channel_id)->toBe($this->channel->id)
        ->and($customer->sales_channel_id)->not->toBe($this->mandali->id);
});

test('a posted sales channel cannot move an existing customer out of the channel', function () {
    $customer = directCustomer($this->business);

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'sales_channel_id' => $this->mandali->id,
    ])->assertRedirect();

    // Still a customer. Changing a buyer's channel is a deliberate act in the buyer
    // master, which re-checks the destination channel's permission.
    expect($customer->fresh()->sales_channel_id)->toBe($this->channel->id);
});

test('creating a customer stores the delivery note, start date and payment cycle', function () {
    $this->post(route('customers.store'), [
        'name' => 'Hetal Dave',
        'delivery_note' => 'Hotel kitchen — deliver before 6am',
        'start_date' => '2026-10-01',
        'payment_cycle' => 'weekly',
        'area' => 'Canal Road',
    ])->assertRedirect();

    $customer = Buyer::query()->where('name', 'Hetal Dave')->firstOrFail();

    expect($customer->delivery_note)->toBe('Hotel kitchen — deliver before 6am')
        ->and($customer->start_date->toDateString())->toBe('2026-10-01')
        ->and($customer->payment_cycle)->toBe('weekly')
        ->and($customer->area)->toBe('Canal Road');
});

test('creating a customer is audited', function () {
    $this->post(route('customers.store'), ['name' => 'Meena Shah'])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'buyer')
        ->where('action', 'created')->latest('id')->firstOrFail();

    expect($log->new_values['name'])->toBe('Meena Shah')
        ->and($log->new_values['sales_channel_id'])->toBe($this->channel->id)
        ->and($log->user_id)->toBe($this->admin->id);
});

test('a material update is audited with the old and new values', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['area' => 'Station Road']);

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'area' => 'Market Lane',
    ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'buyer')
        ->where('action', 'updated')->latest('id')->firstOrFail();

    expect($log->old_values['area'])->toBe('Station Road')
        ->and($log->new_values['area'])->toBe('Market Lane');
});

test('a name is required', function () {
    $this->post(route('customers.store'), ['name' => ''])->assertSessionHasErrors('name');

    expect(Buyer::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D. Preferences: cow, buffalo or both
|--------------------------------------------------------------------------
*/

test('a customer may take cow only', function () {
    $this->post(route('customers.store'), [
        'name' => 'Cow Only',
        'preferences' => [
            'cow' => ['is_active' => 1, 'morning' => '1.000', 'evening' => '2.000'],
            'buffalo' => ['is_active' => 0, 'morning' => '0', 'evening' => '0'],
        ],
    ])->assertRedirect();

    $customer = Buyer::query()->where('name', 'Cow Only')->firstOrFail();

    // The inactive type gets no row at all rather than a row nobody uses.
    expect($customer->preferences)->toHaveCount(1)
        ->and($customer->preferences->first()->milk_type)->toBe(MilkType::Cow);
});

test('a customer may take buffalo only', function () {
    $this->post(route('customers.store'), [
        'name' => 'Buffalo Only',
        'preferences' => ['buffalo' => ['is_active' => 1, 'morning' => '1.000', 'evening' => '1.000']],
    ])->assertRedirect();

    $customer = Buyer::query()->where('name', 'Buffalo Only')->firstOrFail();

    expect($customer->preferences)->toHaveCount(1)
        ->and($customer->preferences->first()->milk_type)->toBe(MilkType::Buffalo);
});

test('a customer may take both', function () {
    $this->post(route('customers.store'), [
        'name' => 'Both Types',
        'preferences' => [
            'cow' => ['is_active' => 1, 'morning' => '1.000', 'evening' => '1.000'],
            'buffalo' => ['is_active' => 1, 'morning' => '0.500', 'evening' => '0.500'],
        ],
    ])->assertRedirect();

    $customer = Buyer::query()->where('name', 'Both Types')->firstOrFail();

    expect($customer->preferences)->toHaveCount(2)
        ->and($customer->activePreferenceFor(MilkType::Cow))->not->toBeNull()
        ->and($customer->activePreferenceFor(MilkType::Buffalo))->not->toBeNull();
});

test('one preference per customer and milk type, enforced by the database', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    expect(fn () => CustomerPreference::factory()->for($customer)->cow()->create())
        ->toThrow(QueryException::class);

    expect(CustomerPreference::query()->where('buyer_id', $customer->id)->count())->toBe(1);
});

test('re-saving preferences updates the existing rows rather than adding more', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    foreach (['1.500', '2.500'] as $morning) {
        $this->put(route('customers.update', $customer), [
            'name' => $customer->name,
            'preferences' => ['cow' => ['is_active' => 1, 'morning' => $morning, 'evening' => '1.000']],
        ])->assertRedirect();
    }

    $preferences = CustomerPreference::query()->where('buyer_id', $customer->id)->get();

    expect($preferences)->toHaveCount(1)
        ->and($preferences->first()->morning_reminder_qty)->toBe('2.500');
});

test('switching a milk type off deactivates the row and keeps it', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'preferences' => ['cow' => ['is_active' => 0, 'morning' => '1.000', 'evening' => '1.000']],
    ])->assertRedirect();

    $preference = CustomerPreference::query()->where('buyer_id', $customer->id)->firstOrFail();

    // Kept, because historical sales of that milk still have to be explainable.
    expect($preference->is_active)->toBeFalse()
        ->and($customer->fresh()->activePreferenceFor(MilkType::Cow))->toBeNull();
});

test('a preference change is audited', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'preferences' => ['cow' => ['is_active' => 1, 'morning' => '3.000', 'evening' => '1.000']],
    ])->assertRedirect();

    $log = AuditLog::query()->where('auditable_type', 'customer_preference')
        ->latest('id')->firstOrFail();

    expect($log->new_values['morning_reminder_qty'])->toBe('3.000');
});

/*
|--------------------------------------------------------------------------
| E. Reminders are reminders — the hard rule
|--------------------------------------------------------------------------
*/

test('reminder quantities are stored exactly as entered', function () {
    $customer = directCustomer($this->business, []);

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'preferences' => ['cow' => ['is_active' => 1, 'morning' => '1.000', 'evening' => '2.000']],
    ])->assertRedirect();

    $preference = CustomerPreference::query()->where('buyer_id', $customer->id)->firstOrFail();

    expect($preference->morningReminder())->toBe('1.000')
        ->and($preference->eveningReminder())->toBe('2.000');
});

test('no method on a preference returns a quantity to save', function () {
    /*
     * The rule is structural, not just documented. A daily entry screen looking for
     * "the quantity for this customer" must not find one here, so the model exposes
     * reminders and nothing that reads like a default or an actual quantity.
     */
    $methods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(CustomerPreference::class))->getMethods(ReflectionMethod::IS_PUBLIC)
    );

    foreach (['quantityFor', 'defaultQuantity', 'dailyQuantity', 'quantity'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }

    // What it does expose is named for what it is.
    expect($methods)->toContain('morningReminder')
        ->and($methods)->toContain('eveningReminder');
});

test('the eligibility service exposes reminders without exposing quantities', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);
    CustomerPreference::query()->where('buyer_id', $customer->id)
        ->update(['morning_reminder_qty' => '5.000', 'evening_reminder_qty' => '5.000']);

    $eligibility = app(CustomerEligibilityService::class)
        ->for($customer->fresh(), now()->toDateString());

    // It hands back the preference so a screen can display it...
    expect($eligibility->reminderFor(MilkType::Cow))->toBeInstanceOf(CustomerPreference::class);

    // ...and offers no method that would give a quantity to save.
    $methods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass($eligibility))->getMethods(ReflectionMethod::IS_PUBLIC)
    );

    foreach (['quantityFor', 'defaultQuantity', 'dailyQuantity'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
});

test('a reminder does not create a sale by itself', function () {
    // The point of the whole rule: a customer with a 5 litre reminder and no entry
    // has no delivery.
    $customer = directCustomer($this->business, [MilkType::Cow]);
    CustomerPreference::query()->where('buyer_id', $customer->id)
        ->update(['morning_reminder_qty' => '5.000', 'evening_reminder_qty' => '5.000']);

    seedProductionFor($this->business->primaryFarm(), now()->toDateString());

    expect(MilkSale::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| F. Archiving
|--------------------------------------------------------------------------
*/

test('archiving a customer keeps the record and its history', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->put(route('customers.status.update', $customer), ['is_active' => 0])->assertRedirect();

    expect($customer->fresh()->is_active)->toBeFalse()
        ->and(Buyer::query()->whereKey($customer->id)->exists())->toBeTrue()
        // Preferences survive, so past deliveries of that milk still make sense.
        ->and(CustomerPreference::query()->where('buyer_id', $customer->id)->count())->toBe(1);
});

test('archiving and restoring are audited as status changes', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->put(route('customers.status.update', $customer), ['is_active' => 0])->assertRedirect();
    $this->put(route('customers.status.update', $customer), ['is_active' => 1])->assertRedirect();

    $actions = AuditLog::query()->where('auditable_type', 'buyer')
        ->whereIn('action', ['activated', 'deactivated'])
        ->orderBy('id')->pluck('action')->map(fn ($a) => $a->value)->all();

    expect($actions)->toBe(['deactivated', 'activated']);
});

test('there is no route for deleting a customer', function () {
    $destructive = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_starts_with($route->uri(), 'customers')
            // The price-override withdrawal is a different thing and is allowed.
            && ! str_contains($route->uri(), 'prices'));

    expect($destructive)->toBeEmpty();
});
