<?php

use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\MilkSale;
use App\Models\User;
use App\Support\Quantity;
use App\Support\RoleCatalog;
use Spatie\Permission\PermissionRegistrar;

/*
 * Who may open the grid, and who may change what is in it.
 *
 * Reaching the route proves only that somebody may look at the day. A single Save
 * Day can create new deliveries and correct existing ones, and those are separate
 * permissions on purpose: an operator trusted with this morning's round is not
 * automatically trusted to rewrite last week.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->customer = directCustomer($this->business, [MilkType::Cow], ['name' => 'Rajesh Patel']);

    $this->payload = fn (?string $morning, ?string $evening = null): array => [
        'date' => $this->date,
        'rows' => [[
            'buyer_id' => $this->customer->id,
            'milk_type' => 'cow',
            'morning' => $morning,
            'evening' => $evening,
        ]],
    ];

    // A delivery already on the books, recorded by someone who was allowed to.
    $this->existing = function (string $quantity): MilkSale {
        $this->actingAs(superAdmin());

        return app(SaveCustomerDailySale::class)
            ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, $quantity);
    };
});

/*
|--------------------------------------------------------------------------
| A. Reading the grid
|--------------------------------------------------------------------------
*/

test('the grid needs the customer delivery view permission', function () {
    $this->actingAs(userWithoutPermissions())
        ->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertForbidden();

    $this->actingAs(userWithPermissions(['milk.customer_delivery.view']))
        ->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertOk();
});

test('a milk permission for another screen does not open this one', function () {
    $this->actingAs(userWithPermissions(['milk.production.view', 'milk.reconciliation.view']))
        ->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertForbidden();
});

test('the save and copy endpoints are closed to someone who cannot view the grid', function () {
    $user = userWithoutPermissions();

    $this->actingAs($user)->postJson(route('milk.customer-entry.store'), ($this->payload)('1.000'))
        ->assertForbidden();

    $this->actingAs($user)->getJson(route('milk.customer-entry.copy-previous', ['date' => $this->date]))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| B. Creating versus correcting
|--------------------------------------------------------------------------
*/

test('a viewer cannot record a delivery', function () {
    $this->actingAs(userWithPermissions(['milk.customer_delivery.view']))
        ->postJson(route('milk.customer-entry.store'), ($this->payload)('1.000'))
        ->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('the create permission records a new delivery', function () {
    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.create',
    ]))->postJson(route('milk.customer-entry.store'), ($this->payload)('1.000'))->assertOk();

    expect(MilkSale::query()->active()->count())->toBe(1);
});

test('the create permission alone cannot change a delivery that already exists', function () {
    ($this->existing)('1.000');

    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.create',
    ]))->postJson(route('milk.customer-entry.store'), ($this->payload)('2.000'))->assertStatus(422);

    expect(Quantity::of(MilkSale::query()->value('quantity')))->toBe('1.000');
});

test('removing an existing delivery is an update, not a create', function () {
    ($this->existing)('1.000');

    // Clearing the field is a correction to the day, so it needs update rights.
    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.create',
    ]))->postJson(route('milk.customer-entry.store'), ($this->payload)(null))->assertStatus(422);

    expect(MilkSale::query()->active()->count())->toBe(1);

    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.update',
    ]))->postJson(route('milk.customer-entry.store'), ($this->payload)(null))->assertOk();

    expect(MilkSale::query()->active()->count())->toBe(0);
});

test('the update permission alone cannot add a delivery where there was none', function () {
    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.update',
    ]))->postJson(route('milk.customer-entry.store'), ($this->payload)('1.000'))->assertStatus(422);

    expect(MilkSale::query()->count())->toBe(0);
});

test('a mixed save needs both permissions, and half of them saves nothing', function () {
    $other = directCustomer($this->business, [MilkType::Cow], ['name' => 'Amit Shah']);

    ($this->existing)('1.000');

    $mixed = [
        'date' => $this->date,
        'rows' => [
            // A correction...
            ['buyer_id' => $this->customer->id, 'milk_type' => 'cow', 'morning' => '2.000', 'evening' => null],
            // ...and a brand-new delivery.
            ['buyer_id' => $other->id, 'milk_type' => 'cow', 'morning' => '3.000', 'evening' => null],
        ],
    ];

    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.update',
    ]))->postJson(route('milk.customer-entry.store'), $mixed)->assertStatus(422);

    // Neither half was applied: the request is one operation.
    expect(Quantity::of(MilkSale::query()->where('buyer_id', $this->customer->id)->value('quantity')))
        ->toBe('1.000')
        ->and(MilkSale::query()->where('buyer_id', $other->id)->count())->toBe(0);

    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.create', 'milk.customer_delivery.update',
    ]))->postJson(route('milk.customer-entry.store'), $mixed)->assertOk();

    expect(MilkSale::query()->active()->count())->toBe(2);
});

test('a save that changes nothing needs no write permission at all', function () {
    ($this->existing)('1.000');

    // Re-submitting the day unchanged is not a write, so a viewer may do it.
    $this->actingAs(userWithPermissions(['milk.customer_delivery.view']))
        ->postJson(route('milk.customer-entry.store'), ($this->payload)('1.000'))
        ->assertOk()
        ->assertJsonPath('summary.changed', 0);
});

/*
|--------------------------------------------------------------------------
| C. The screen reflects the permissions
|--------------------------------------------------------------------------
*/

test('a viewer sees the day read-only, with no save or copy button', function () {
    $html = $this->actingAs(userWithPermissions(['milk.customer_delivery.view']))
        ->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.customer_entry.read_only'))
        ->getContent();

    expect($html)->not->toContain('data-entry-save')
        ->not->toContain('data-entry-copy');

    // Every field is closed, so nothing invites an edit that would be refused.
    expect($html)->toMatch('/<input[^>]*data-entry-input[^>]*disabled/');
});

test('someone who may write sees the controls', function () {
    $this->actingAs(userWithPermissions([
        'milk.customer_delivery.view', 'milk.customer_delivery.create',
    ]))->get(route('milk.customer-entry.index', ['date' => $this->date]))
        ->assertOk()
        ->assertSee(__('milk.customer_entry.save_day'))
        ->assertDontSee(__('milk.customer_entry.read_only'));
});

/*
|--------------------------------------------------------------------------
| D. Seeded roles
|--------------------------------------------------------------------------
*/

test('the seeded roles reach the grid as the permission matrix says they should', function (string $role, bool $canView, bool $canWrite) {
    $user = User::factory()->create();
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $response = $this->actingAs($user->refresh())
        ->get(route('milk.customer-entry.index', ['date' => $this->date]));

    $canView ? $response->assertOk() : $response->assertForbidden();

    if (! $canView) {
        return;
    }

    $save = $this->actingAs($user)
        ->postJson(route('milk.customer-entry.store'), ($this->payload)('1.000'));

    $canWrite ? $save->assertOk() : $save->assertStatus(422);
})->with([
    'Super Admin' => [RoleCatalog::SUPER_ADMIN, true, true],
    'Owner' => [RoleCatalog::OWNER, true, true],
    'Manager' => [RoleCatalog::MANAGER, true, true],
    'Data Operator' => [RoleCatalog::DATA_OPERATOR, true, true],
    // Visibility without the round: they see the day, they do not enter it.
    'Accountant' => [RoleCatalog::ACCOUNTANT, true, false],
    'Partner' => [RoleCatalog::PARTNER, true, false],
    'Viewer' => [RoleCatalog::VIEWER, true, false],
]);

test('grid authorisation never branches on a role name', function () {
    $source = file_get_contents(app_path('Actions/Milk/SaveCustomerDailyDeliveries.php'))
        .file_get_contents(app_path('Http/Controllers/Milk/CustomerDailyEntryController.php'))
        .file_get_contents(app_path('Http/Requests/Milk/SaveCustomerDailyEntryRequest.php'));

    foreach (['hasRole', 'Super Admin', 'Owner', 'Manager', 'Accountant', 'Data Operator', 'Viewer'] as $needle) {
        expect($source)->not->toContain($needle);
    }
});
