<?php

use App\Models\Business;
use App\Models\Buyer;
use App\Models\SalesChannel;
use App\Support\BuyerPermissions;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->mandali = SalesChannel::query()->where('slug', SalesChannel::MANDALI)->firstOrFail();
    $this->vendor = SalesChannel::query()->where('slug', SalesChannel::VENDOR)->firstOrFail();
    $this->customer = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $this->custom = SalesChannel::factory()->for($this->business)->create([
        'slug' => 'sweet_shop', 'name' => 'Sweet Shop',
    ]);
});

/*
|--------------------------------------------------------------------------
| The generic identity
|--------------------------------------------------------------------------
*/

test('one buyer table serves every channel', function () {
    // Separate mandalis/vendors/customers tables would triple the identity,
    // payment and outstanding logic for no gain.
    foreach (['mandalis', 'vendors', 'customers', 'direct_customers'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasTable('buyers'))->toBeTrue();
});

test('a buyer belongs to a business and a channel', function () {
    $buyer = Buyer::factory()->inChannel($this->mandali)->create();

    expect($buyer->business_id)->toBe($this->business->id)
        ->and($buyer->salesChannel->slug)->toBe('mandali')
        ->and($buyer->channelSlug())->toBe('mandali');
});

test('outstanding balance is not a stored column', function () {
    // It is derived from sales, payments and adjustments in later phases.
    expect(Schema::getColumnListing('buyers'))
        ->not->toContain('outstanding')
        ->not->toContain('balance')
        ->not->toContain('outstanding_amount');
});

test('milk preferences are their own table, not columns on the buyer', function () {
    /*
     * Phase 4 added the two buyer-level customer fields that had no reader before
     * (`delivery_note` and `start_date`), so the original form of this guard has been
     * inverted rather than deleted. What it still protects is the part that matters:
     * reminders live in `customer_preferences`, one row per milk type, and are never
     * flattened onto the buyer — which is what would cap the milk types at two and
     * leave a buffalo-only customer carrying meaningless cow columns.
     */
    expect(Schema::getColumnListing('buyers'))
        ->not->toContain('morning_reminder_qty')
        ->not->toContain('evening_reminder_qty')
        ->not->toContain('cow_morning_qty')
        ->not->toContain('buffalo_morning_qty');

    expect(Schema::hasTable('customer_preferences'))->toBeTrue()
        ->and(Schema::hasColumn('customer_preferences', 'milk_type'))->toBeTrue()
        ->and(Schema::hasColumn('customer_preferences', 'morning_reminder_qty'))->toBeTrue();

    // The two Phase 4 buyer columns are on the buyer, where the specification puts
    // them (MASTER_SPEC section 16).
    expect(Schema::getColumnListing('buyers'))
        ->toContain('delivery_note')
        ->toContain('start_date');
});

test('an authorised user can create, update and deactivate a buyer', function () {
    $user = userWithPermissions(['customer.view', 'customer.create', 'customer.update']);

    $this->actingAs($user)->post(route('buyers.store'), [
        'sales_channel_id' => $this->customer->id,
        'name' => 'Rajesh Patel',
        'mobile' => '9876543210',
        'area' => 'North',
        'payment_cycle' => 'monthly',
    ])->assertRedirect(route('buyers.index'));

    $buyer = Buyer::query()->where('name', 'Rajesh Patel')->firstOrFail();

    expect($buyer->area)->toBe('North')
        ->and($buyer->payment_cycle)->toBe('monthly')
        ->and($buyer->business_id)->toBe($this->business->id);

    $this->actingAs($user)->put(route('buyers.update', $buyer), [
        'sales_channel_id' => $this->customer->id,
        'name' => 'Rajesh M Patel',
        'area' => 'South',
    ])->assertRedirect();

    expect($buyer->fresh()->name)->toBe('Rajesh M Patel');

    $this->actingAs($user)->put(route('buyers.status.update', $buyer), ['is_active' => 0])->assertRedirect();
    expect($buyer->fresh()->is_active)->toBeFalse();
});

test('a channel from another business cannot be assigned', function () {
    $other = Business::factory()->create();
    $foreignChannel = SalesChannel::factory()->for($other)->create();

    $user = userWithPermissions(['customer.view', 'customer.create']);

    $this->actingAs($user)->post(route('buyers.store'), [
        'sales_channel_id' => $foreignChannel->id,
        'name' => 'Foreign Buyer',
    ])->assertSessionHasErrors('sales_channel_id');

    expect(Buyer::query()->where('name', 'Foreign Buyer')->exists())->toBeFalse();
});

test('there is no route for deleting a buyer', function () {
    expect(app('router')->getRoutes()->getByName('buyers.destroy'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Channel-driven authorisation
|--------------------------------------------------------------------------
*/

test('each channel maps to its own permission family', function () {
    expect(BuyerPermissions::familyFor($this->mandali))->toBe('mandali')
        ->and(BuyerPermissions::familyFor($this->vendor))->toBe('vendor')
        ->and(BuyerPermissions::familyFor($this->customer))->toBe('customer')
        // A custom channel is commercially a direct buyer.
        ->and(BuyerPermissions::familyFor($this->custom))->toBe('customer');
});

test('a buyer is visible only to someone holding its channel permission', function (string $channelProperty, string $family) {
    $buyer = Buyer::factory()->inChannel($this->{$channelProperty})->create();

    $allowed = userWithPermissions([$family.'.view']);
    $this->actingAs($allowed)->get(route('buyers.show', $buyer))->assertOk();

    // Someone with a different channel's rights is refused, not just unlinked.
    $wrongFamily = $family === 'mandali' ? 'vendor' : 'mandali';
    $denied = userWithPermissions([$wrongFamily.'.view']);
    $this->actingAs($denied)->get(route('buyers.show', $buyer))->assertForbidden();
})->with([
    'mandali buyer' => ['mandali', 'mandali'],
    'vendor buyer' => ['vendor', 'vendor'],
    'direct customer' => ['customer', 'customer'],
    'custom channel buyer' => ['custom', 'customer'],
]);

test('editing a buyer needs the update permission of its own channel', function () {
    $buyer = Buyer::factory()->inChannel($this->mandali)->create();

    $viewOnly = userWithPermissions(['mandali.view']);
    $this->actingAs($viewOnly)->get(route('buyers.edit', $buyer))->assertForbidden();

    $vendorEditor = userWithPermissions(['vendor.view', 'vendor.update']);
    $this->actingAs($vendorEditor)->get(route('buyers.edit', $buyer))->assertForbidden();

    $mandaliEditor = userWithPermissions(['mandali.view', 'mandali.update']);
    $this->actingAs($mandaliEditor)->get(route('buyers.edit', $buyer))->assertOk();
});

test('creating in a channel needs the create permission of that channel', function () {
    $vendorCreator = userWithPermissions(['vendor.view', 'vendor.create']);

    // Allowed in their own channel.
    $this->actingAs($vendorCreator)->post(route('buyers.store'), [
        'sales_channel_id' => $this->vendor->id,
        'name' => 'Local Dairy',
    ])->assertRedirect();

    // Refused in another.
    $this->actingAs($vendorCreator)->post(route('buyers.store'), [
        'sales_channel_id' => $this->mandali->id,
        'name' => 'Village Mandali',
    ])->assertForbidden();

    expect(Buyer::query()->where('name', 'Village Mandali')->exists())->toBeFalse();
});

test('a buyer cannot be moved into a channel the user has no rights in', function () {
    /*
     * Otherwise someone with only vendor rights could move a buyer into Mandali
     * and keep editing it, side-stepping the Mandali permissions entirely.
     */
    $buyer = Buyer::factory()->inChannel($this->vendor)->create();
    $vendorEditor = userWithPermissions(['vendor.view', 'vendor.update', 'vendor.create']);

    $this->actingAs($vendorEditor)->put(route('buyers.update', $buyer), [
        'sales_channel_id' => $this->mandali->id,
        'name' => $buyer->name,
    ])->assertForbidden();

    expect($buyer->fresh()->sales_channel_id)->toBe($this->vendor->id);
});

test('a buyer can be moved between channels the user does hold', function () {
    $buyer = Buyer::factory()->inChannel($this->vendor)->create();

    $user = userWithPermissions([
        'vendor.view', 'vendor.update', 'vendor.create',
        'mandali.view', 'mandali.update', 'mandali.create',
    ]);

    $this->actingAs($user)->put(route('buyers.update', $buyer), [
        'sales_channel_id' => $this->mandali->id,
        'name' => $buyer->name,
    ])->assertRedirect();

    expect($buyer->fresh()->sales_channel_id)->toBe($this->mandali->id);
});

test('the list shows only the channels the user may view', function () {
    Buyer::factory()->inChannel($this->mandali)->create(['name' => 'Village Mandali']);
    Buyer::factory()->inChannel($this->vendor)->create(['name' => 'Local Dairy']);
    Buyer::factory()->inChannel($this->customer)->create(['name' => 'Rajesh Patel']);
    Buyer::factory()->inChannel($this->custom)->create(['name' => 'Corner Sweet Shop']);

    $vendorOnly = userWithPermissions(['vendor.view']);

    $this->actingAs($vendorOnly)->get(route('buyers.index'))
        ->assertOk()
        ->assertSee('Local Dairy')
        ->assertDontSee('Village Mandali')
        ->assertDontSee('Rajesh Patel')
        ->assertDontSee('Corner Sweet Shop');
});

test('custom channel buyers appear for someone holding customer rights', function () {
    Buyer::factory()->inChannel($this->custom)->create(['name' => 'Corner Sweet Shop']);

    $customerViewer = userWithPermissions(['customer.view']);

    $this->actingAs($customerViewer)->get(route('buyers.index'))
        ->assertOk()
        ->assertSee('Corner Sweet Shop');
});

test('a user with no buyer permission at all cannot reach the list', function () {
    $user = userWithPermissions(['dashboard.view']);

    $this->actingAs($user)->get(route('buyers.index'))->assertForbidden();
    $this->actingAs($user)->get(route('buyers.create'))->assertForbidden();
});

test('buyer authorisation never branches on a role name', function () {
    $source = file_get_contents(app_path('Policies/BuyerPolicy.php'))
        .file_get_contents(app_path('Support/BuyerPermissions.php'))
        .file_get_contents(app_path('Http/Controllers/BuyerController.php'));

    foreach (['hasRole', 'Super Admin', 'Owner', 'Manager', 'Accountant', 'Data Operator', 'Viewer'] as $needle) {
        expect($source)->not->toContain($needle);
    }
});

/*
|--------------------------------------------------------------------------
| Filtering
|--------------------------------------------------------------------------
*/

test('the list can be searched and filtered', function () {
    $user = userWithPermissions(['customer.view']);

    Buyer::factory()->inChannel($this->customer)->create(['name' => 'Rajesh Patel', 'area' => 'North']);
    Buyer::factory()->inChannel($this->customer)->create(['name' => 'Amit Shah', 'area' => 'South']);
    Buyer::factory()->inChannel($this->customer)->inactive()->create(['name' => 'Old Customer']);

    $this->actingAs($user)->get(route('buyers.index', ['search' => 'Rajesh']))
        ->assertSee('Rajesh Patel')->assertDontSee('Amit Shah');

    $this->actingAs($user)->get(route('buyers.index', ['area' => 'South']))
        ->assertSee('Amit Shah')->assertDontSee('Rajesh Patel');

    $this->actingAs($user)->get(route('buyers.index', ['status' => 'inactive']))
        ->assertSee('Old Customer')->assertDontSee('Rajesh Patel');
});
