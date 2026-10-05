<?php

use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Customers\CreateCustomerPause;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\CustomerPause;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 4 authorisation.
 *
 * Every check is against the server. The customer family governs these rows because
 * BuyerPolicy resolves the permission from the record's sales channel, so nothing
 * here branches on a role name — asserted separately by the source scan in
 * RolePermissionTest.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '1000.00');
    $this->cash = $accounts['cash'];

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business);
    seedProductionFor($this->farm, $this->date);

    $this->customer = directCustomer($this->business, [MilkType::Cow]);

    // Something owed, so a payment is possible.
    $this->actingAs(superAdmin());
    app(SaveCustomerDailySale::class)
        ->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000');

    $this->payment = app(RecordBuyerPayment::class)->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $this->pause = app(CreateCustomerPause::class)
        ->handle($this->customer, '2026-11-01', '2026-11-05');
});

/*
|--------------------------------------------------------------------------
| A. Reads
|--------------------------------------------------------------------------
*/

test('the customer list is refused without customer.view and allowed with it', function () {
    $this->actingAs(userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]))
        ->get(route('customers.index'))->assertForbidden();

    $this->actingAs(userWithPermissions(['customer.view']))
        ->get(route('customers.index'))->assertOk();
});

test('a customer profile is refused without customer.view', function () {
    $this->actingAs(userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]))
        ->get(route('customers.show', $this->customer))->assertForbidden();

    $this->actingAs(userWithPermissions(['customer.view']))
        ->get(route('customers.show', $this->customer))->assertOk();
});

test('a guest is redirected to sign in', function (string $routeName) {
    // beforeEach signs in an administrator to set the fixtures up, so the session has
    // to be dropped before this means anything.
    Auth::logout();
    $this->flushSession();

    $this->get(route($routeName, $this->customer))->assertRedirect(route('login'));
})->with(['customers.index', 'customers.show', 'customers.edit']);

test('holding only mandali or vendor rights does not open a customer', function () {
    // The channel decides the family, so a Mandali viewer cannot read a customer.
    $user = userWithPermissions(['mandali.view', 'vendor.view']);

    $this->actingAs($user)->get(route('customers.show', $this->customer))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| B. Writes
|--------------------------------------------------------------------------
*/

test('creating a customer needs customer.create', function () {
    $user = userWithPermissions(['customer.view']);

    $this->actingAs($user)->get(route('customers.create'))->assertForbidden();
    $this->actingAs($user)->post(route('customers.store'), ['name' => 'Sneaky'])->assertForbidden();

    expect(Buyer::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

test('editing a customer needs customer.update', function () {
    $user = userWithPermissions(['customer.view']);

    $this->actingAs($user)->get(route('customers.edit', $this->customer))->assertForbidden();
    $this->actingAs($user)->put(route('customers.update', $this->customer), [
        'name' => 'Hijacked',
    ])->assertForbidden();

    expect($this->customer->fresh()->name)->not->toBe('Hijacked');
});

test('archiving a customer needs customer.archive, which update does not imply', function () {
    $user = userWithPermissions(['customer.view', 'customer.update']);

    $this->actingAs($user)->put(route('customers.status.update', $this->customer), [
        'is_active' => 0,
    ])->assertForbidden();

    expect($this->customer->fresh()->is_active)->toBeTrue();
});

test('customer.archive permits archiving', function () {
    $user = userWithPermissions(['customer.view', 'customer.archive']);

    $this->actingAs($user)->put(route('customers.status.update', $this->customer), [
        'is_active' => 0,
    ])->assertRedirect();

    expect($this->customer->fresh()->is_active)->toBeFalse();
});

test('pausing a customer needs customer.update', function () {
    $user = userWithPermissions(['customer.view']);

    $this->actingAs($user)->post(route('customers.pauses.store', $this->customer), [
        'start_date' => '2026-12-01',
    ])->assertForbidden();

    expect(CustomerPause::query()->count())->toBe(1);
});

test('setting a price override needs customer.update', function () {
    $user = userWithPermissions(['customer.view']);

    $this->actingAs($user)->post(route('customers.prices.store', $this->customer), [
        'milk_type' => MilkType::Cow->value,
        'rate' => '99.00',
        'effective_from' => '2026-12-01',
    ])->assertForbidden();

    expect($this->customer->priceRules()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. Payments
|--------------------------------------------------------------------------
*/

test('recording a payment needs customer.payment.create', function () {
    $user = userWithPermissions(['customer.view', 'customer.update']);

    $this->actingAs($user)->post(route('customers.payments.store', $this->customer), [
        'payment_date' => $this->date,
        'amount' => '50.00',
        'financial_account_id' => $this->cash->id,
    ])->assertForbidden();

    expect(BuyerPayment::query()->count())->toBe(1);
});

test('customer.payment.create permits recording one', function () {
    $user = userWithPermissions(['customer.view', 'customer.payment.create']);

    $this->actingAs($user)->post(route('customers.payments.store', $this->customer), [
        'payment_date' => $this->date,
        'amount' => '50.00',
        'financial_account_id' => $this->cash->id,
    ])->assertRedirect();

    expect(BuyerPayment::query()->count())->toBe(2);
});

test('cancelling a payment needs its own permission, which create does not imply', function () {
    // Recording a receipt and reversing money in the ledger are different decisions.
    $user = userWithPermissions(['customer.view', 'customer.payment.create']);

    $this->actingAs($user)->put(route('customers.payments.cancel', $this->payment), [
        'cancellation_reason' => 'Trying it on without the permission',
    ])->assertForbidden();

    expect($this->payment->fresh()->isCancelled())->toBeFalse();
});

test('customer.payment.cancel permits the cancellation', function () {
    $user = userWithPermissions(['customer.view', 'customer.payment.cancel']);

    $this->actingAs($user)->put(route('customers.payments.cancel', $this->payment), [
        'cancellation_reason' => 'Cheque bounced',
    ])->assertRedirect();

    expect($this->payment->fresh()->isCancelled())->toBeTrue();
});

test('the new payment cancel permission is seeded in the customers group', function () {
    expect(PermissionCatalog::all())->toContain('customer.payment.cancel')
        ->and(PermissionCatalog::isSystem('customer.payment.cancel'))->toBeTrue()
        ->and(PermissionCatalog::groupOf('customer.payment.cancel'))->toBe('customers')
        ->and(Permission::query()
            ->where('name', 'customer.payment.cancel')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| D. Seeded roles on the real Phase 4 surface
|--------------------------------------------------------------------------
*/

test('the seeded Accountant records payments but cannot cancel them', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::ACCOUNTANT);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('customers.show', $this->customer))->assertOk();

    $this->actingAs($user)->post(route('customers.payments.store', $this->customer), [
        'payment_date' => $this->date, 'amount' => '50.00',
        'financial_account_id' => $this->cash->id,
    ])->assertRedirect();

    // Consistent with the Accountant already being excluded from expense.cancel.
    $this->actingAs($user)->put(route('customers.payments.cancel', $this->payment), [
        'cancellation_reason' => 'Should not be permitted',
    ])->assertForbidden();
});

test('the seeded Owner holds the whole customer surface including cancellation', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::OWNER);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('customers.index'))->assertOk();
    $this->actingAs($user)->get(route('customers.create'))->assertOk();

    $this->actingAs($user)->put(route('customers.payments.cancel', $this->payment), [
        'cancellation_reason' => 'An owner-level correction',
    ])->assertRedirect();

    expect($this->payment->fresh()->isCancelled())->toBeTrue();
});

test('the seeded Data Operator can read customers but not change them', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::DATA_OPERATOR);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('customers.index'))->assertOk();

    $this->actingAs($user)->get(route('customers.create'))->assertForbidden();
    $this->actingAs($user)->put(route('customers.update', $this->customer), [
        'name' => 'Hijacked',
    ])->assertForbidden();
    $this->actingAs($user)->post(route('customers.payments.store', $this->customer), [
        'payment_date' => $this->date, 'amount' => '10.00',
        'financial_account_id' => $this->cash->id,
    ])->assertForbidden();
});

test('the seeded Viewer reads customers and changes nothing', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::VIEWER);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('customers.index'))->assertOk();
    $this->actingAs($user)->get(route('customers.show', $this->customer))->assertOk();

    $this->actingAs($user)->post(route('customers.store'), ['name' => 'No'])->assertForbidden();
    $this->actingAs($user)->post(route('customers.payments.store', $this->customer), [
        'payment_date' => $this->date, 'amount' => '10.00',
        'financial_account_id' => $this->cash->id,
    ])->assertForbidden();
});

test('a revoked customer permission takes effect on the next request', function () {
    $user = userWithPermissions(['customer.view']);
    $role = $user->roles->first();

    $this->actingAs($user)->get(route('customers.index'))->assertOk();

    $role->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())->get(route('customers.index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| E. Navigation reflects permissions but is not the protection
|--------------------------------------------------------------------------
*/

test('the customer link is hidden from someone without customer.view', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain(route('customers.index'));
});

test('the customer link is shown to someone with customer.view', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW, 'customer.view']);

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain(route('customers.index'))
        ->and($html)->toContain(__('nav.direct_customers'));
});

test('the Customer Daily Entry link lives under Milk, not under Customers', function () {
    /*
     * Until Pass 2 this asserted the link was absent, because the grid did not
     * exist. It exists now, and the guard keeps the part that still matters: it is
     * a milk-distribution screen filed with the milk round, and the customer
     * administration menu does not advertise it.
     */
    $html = $this->actingAs(superAdmin())->get(route('customers.index'))->assertOk()->getContent();

    expect(strtolower($html))->not->toContain('coming soon');

    // Present in the sidebar, under the Milk heading.
    $milkSection = substr($html, (int) strpos($html, __('nav.milk')));

    expect($milkSection)->toContain(route('milk.customer-entry.index'));
});

/*
|--------------------------------------------------------------------------
| F. No role-name authorisation crept in
|--------------------------------------------------------------------------
*/

test('the Phase 4 source introduces no role-name check', function () {
    // The project-wide scan lives in RolePermissionTest; this narrows it to the new
    // directories so a failure points at the right place.
    $sources = '';

    foreach ([
        app_path('Actions/Customers'),
        app_path('Actions/Buyers'),
        app_path('Services/Customers'),
        app_path('Services/Buyers'),
        app_path('Http/Controllers/Customers'),
    ] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources .= file_get_contents($file->getPathname());
            }
        }
    }

    foreach (['hasRole(', 'hasAnyRole(', 'hasAllRoles(', 'Gate::before'] as $needle) {
        expect($sources)->not->toContain($needle);
    }
});
