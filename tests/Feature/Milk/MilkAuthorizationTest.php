<?php

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 3 authorisation.
 *
 * Every check is made against the server, not against what the sidebar chose to
 * show. Hiding a link is tidiness; the refusal is the protection.
 *
 * The seeded role bundles are asserted too, but that is a check on the *data* the
 * seeder writes. Production authorisation resolves only through permissions, and a
 * separate source scan (RolePermissionTest) fails the build if a role name ever
 * decides access again.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-09-10';

    $this->production = MilkProduction::factory()->for($this->farm)->morning()->on($this->date)
        ->quantities('100.000', '100.000')->create();

    $this->usage = MilkUsage::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->quantity('2.000')->create();

    $this->adjustment = MilkAdjustment::factory()->for($this->farm)->on($this->date)
        ->shift(Shift::Morning)->milkType(MilkType::Cow)->increase('1.000')->create();

    $this->usagePayload = [
        'usage_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'usage_type' => MilkUsageType::CalfFeeding->value,
        'quantity' => '1.000',
    ];

    $this->adjustmentPayload = [
        'adjustment_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '1.000',
        'reason' => 'A genuine measurement difference',
    ];
});

/*
|--------------------------------------------------------------------------
| A. Reads
|--------------------------------------------------------------------------
*/

test('a milk page is refused without its permission and allowed with it', function (string $routeName, string $permission) {
    $without = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);
    $this->actingAs($without)->get(route($routeName))->assertForbidden();

    $with = userWithPermissions([$permission]);
    $this->actingAs($with)->get(route($routeName))->assertOk();
})->with([
    'production' => ['milk.production.index', 'milk.production.view'],
    'usage' => ['milk.usage.index', 'milk.usage.view'],
    'adjustments' => ['milk.adjustments.index', 'milk.adjustment.create'],
    'reconciliation' => ['milk.reconciliation', 'milk.reconciliation.view'],
]);

test('reconciliation is refused to a signed-in user holding nothing', function () {
    $this->actingAs(userWithoutPermissions())->get(route('milk.reconciliation'))->assertForbidden();
});

test('a guest is redirected to sign in rather than shown a milk page', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with(['milk.production.index', 'milk.usage.index', 'milk.reconciliation', 'milk.adjustments.index']);

/*
|--------------------------------------------------------------------------
| B. Production writes
|--------------------------------------------------------------------------
*/

test('saving production is refused with only the view permission', function () {
    $user = userWithPermissions(['milk.production.view']);

    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '5.000']],
    ])->assertForbidden();

    expect(MilkProduction::query()->whereDate('production_date', '2026-09-20')->exists())->toBeFalse();
});

test('milk.production.create allows both first entry and correction', function () {
    // There is no separate update route: production is saved and re-saved through
    // the same endpoint, so create is the permission that governs both.
    $user = userWithPermissions(['milk.production.view', 'milk.production.create']);

    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '6.000', 'buffalo' => '0']],
    ])->assertRedirect();

    expect(MilkProduction::query()->whereDate('production_date', '2026-09-20')->count())->toBe(1)
        ->and(MilkProduction::query()->whereDate('production_date', '2026-09-20')->value('cow_milk_quantity'))
        ->toBe('6.000');
});

test('milk.production.update alone does not permit saving', function () {
    // Holding only update is not a way in through the create-shaped endpoint.
    $user = userWithPermissions(['milk.production.view', 'milk.production.update']);

    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '0']],
    ])->assertForbidden();
});

test('the unused production delete permission grants access to nothing', function () {
    // The identifier is seeded because MASTER_SPEC section 10 lists it. Phase 3
    // exposes no destructive endpoint, so holding it opens nothing (D33).
    $user = userWithPermissions(['milk.production.view', 'milk.production.delete']);

    $this->actingAs($user)->get(route('milk.production.index'))->assertOk();

    // Saving still needs create.
    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '0']],
    ])->assertForbidden();

    // And there is nothing to delete with.
    $milkRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'milk'))
        ->flatMap(fn ($route): array => $route->methods());

    expect($milkRoutes)->not->toContain('DELETE');
});

/*
|--------------------------------------------------------------------------
| C. Usage writes
|--------------------------------------------------------------------------
*/

test('recording usage needs milk.usage.create, not merely the view', function () {
    $user = userWithPermissions(['milk.usage.view']);

    $this->actingAs($user)->get(route('milk.usage.index'))->assertOk();
    $this->actingAs($user)->post(route('milk.usage.store'), $this->usagePayload)->assertForbidden();

    expect(MilkUsage::query()->count())->toBe(1);
});

test('cancelling usage needs milk.usage.cancel, which create does not imply', function () {
    $user = userWithPermissions(['milk.usage.view', 'milk.usage.create']);

    $this->actingAs($user)->put(route('milk.usage.cancel', $this->usage), [
        'cancellation_reason' => 'Trying it on without the permission',
    ])->assertForbidden();

    expect($this->usage->fresh()->isCancelled())->toBeFalse();
});

test('milk.usage.cancel permits the cancellation', function () {
    $user = userWithPermissions(['milk.usage.view', 'milk.usage.cancel']);

    $this->actingAs($user)->put(route('milk.usage.cancel', $this->usage), [
        'cancellation_reason' => 'Recorded against the wrong shift',
    ])->assertRedirect();

    expect($this->usage->fresh()->isCancelled())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| D. Adjustment writes — the capability that must not leak
|--------------------------------------------------------------------------
*/

test('production and usage rights do not confer adjustment rights', function () {
    // The whole point of the separate permission: someone who records the day's
    // milk cannot also declare that more milk existed than was recorded.
    $user = userWithPermissions([
        'milk.production.view', 'milk.production.create',
        'milk.usage.view', 'milk.usage.create',
        'milk.reconciliation.view',
    ]);

    $this->actingAs($user)->get(route('milk.adjustments.index'))->assertForbidden();
    $this->actingAs($user)->post(route('milk.adjustments.store'), $this->adjustmentPayload)->assertForbidden();

    expect(MilkAdjustment::query()->count())->toBe(1);
});

test('milk.adjustment.create permits recording one', function () {
    $user = userWithPermissions(['milk.adjustment.create']);

    $this->actingAs($user)->post(route('milk.adjustments.store'), $this->adjustmentPayload)
        ->assertRedirect();

    expect(MilkAdjustment::query()->count())->toBe(2);
});

test('cancelling an adjustment needs its own permission, which create does not imply', function () {
    $user = userWithPermissions(['milk.adjustment.create']);

    $this->actingAs($user)->put(route('milk.adjustments.cancel', $this->adjustment), [
        'cancellation_reason' => 'Trying it on without the permission',
    ])->assertForbidden();

    expect($this->adjustment->fresh()->isCancelled())->toBeFalse();
});

test('milk.adjustment.cancel permits the cancellation', function () {
    $user = userWithPermissions(['milk.adjustment.create', 'milk.adjustment.cancel']);

    $this->actingAs($user)->put(route('milk.adjustments.cancel', $this->adjustment), [
        'cancellation_reason' => 'Withdrawn after a recount',
    ])->assertRedirect();

    expect($this->adjustment->fresh()->isCancelled())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| E. The four new identifiers are seeded
|--------------------------------------------------------------------------
*/

test('the catalogue contains the four Phase 3 permissions', function (string $permission) {
    expect(PermissionCatalog::all())->toContain($permission)
        ->and(PermissionCatalog::isSystem($permission))->toBeTrue()
        ->and(PermissionCatalog::groupOf($permission))->toBe('milk')
        ->and(Permission::query()->where('name', $permission)->exists())->toBeTrue();
})->with([
    'milk.usage.view',
    'milk.usage.create',
    'milk.usage.cancel',
    'milk.adjustment.cancel',
]);

test('there is no usage update permission, because there is no update route', function () {
    expect(PermissionCatalog::all())->not->toContain('milk.usage.update');
});

/*
|--------------------------------------------------------------------------
| F. Seeded role bundles on the real Phase 3 surface
|--------------------------------------------------------------------------
*/

test('the seeded Manager can record usage but not cancel it and not adjust', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::MANAGER);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('milk.production.index'))->assertOk();
    $this->actingAs($user)->get(route('milk.usage.index'))->assertOk();
    $this->actingAs($user)->post(route('milk.usage.store'), $this->usagePayload)->assertRedirect();

    // Corrections and exceptions stay with the Owner.
    $this->actingAs($user)->put(route('milk.usage.cancel', $this->usage), [
        'cancellation_reason' => 'Should not be permitted',
    ])->assertForbidden();

    $this->actingAs($user)->get(route('milk.adjustments.index'))->assertForbidden();
});

test('the seeded Data Operator can enter production and usage only', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::DATA_OPERATOR);
    $user = $user->fresh();

    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '0']],
    ])->assertRedirect();

    $this->actingAs($user)->post(route('milk.usage.store'), $this->usagePayload)->assertRedirect();

    $this->actingAs($user)->get(route('milk.adjustments.index'))->assertForbidden();
    $this->actingAs($user)->put(route('milk.usage.cancel', $this->usage), [
        'cancellation_reason' => 'Should not be permitted',
    ])->assertForbidden();
});

test('the seeded Owner holds the whole Phase 3 surface including the exceptions', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::OWNER);
    $user = $user->fresh();

    foreach (['milk.production.index', 'milk.usage.index', 'milk.adjustments.index', 'milk.reconciliation'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }

    $this->actingAs($user)->post(route('milk.adjustments.store'), $this->adjustmentPayload)->assertRedirect();

    $this->actingAs($user)->put(route('milk.usage.cancel', $this->usage), [
        'cancellation_reason' => 'An owner-level correction',
    ])->assertRedirect();
});

test('the seeded Viewer reads milk and changes nothing', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::VIEWER);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('milk.production.index'))->assertOk();
    $this->actingAs($user)->get(route('milk.usage.index'))->assertOk();
    $this->actingAs($user)->get(route('milk.reconciliation'))->assertOk();

    $this->actingAs($user)->post(route('milk.usage.store'), $this->usagePayload)->assertForbidden();
    $this->actingAs($user)->post(route('milk.production.store'), [
        'production_date' => '2026-09-20',
        'shifts' => ['morning' => ['cow' => '5.000', 'buffalo' => '0']],
    ])->assertForbidden();
    $this->actingAs($user)->get(route('milk.adjustments.index'))->assertForbidden();
});

test('the seeded Accountant reads milk without recording it', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleCatalog::ACCOUNTANT);
    $user = $user->fresh();

    $this->actingAs($user)->get(route('milk.reconciliation'))->assertOk();
    $this->actingAs($user)->get(route('milk.usage.index'))->assertOk();

    $this->actingAs($user)->post(route('milk.usage.store'), $this->usagePayload)->assertForbidden();
    $this->actingAs($user)->get(route('milk.adjustments.index'))->assertForbidden();
});

test('a revoked milk permission takes effect on the next request', function () {
    $user = userWithPermissions(['milk.usage.view']);
    $role = $user->roles->first();

    $this->actingAs($user)->get(route('milk.usage.index'))->assertOk();

    $role->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())->get(route('milk.usage.index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| G. The sidebar reflects permissions but is not the protection
|--------------------------------------------------------------------------
*/

test('the adjustments link is hidden from someone who may not record one', function () {
    $user = userWithPermissions(['milk.production.view', 'milk.reconciliation.view']);

    $html = $this->actingAs($user)->get(route('milk.production.index'))->assertOk()->getContent();

    expect($html)->not->toContain(route('milk.adjustments.index'));
});

test('the adjustments link is shown to someone who may', function () {
    $user = userWithPermissions(['milk.production.view', 'milk.adjustment.create']);

    $html = $this->actingAs($user)->get(route('milk.production.index'))->assertOk()->getContent();

    expect($html)->toContain(route('milk.adjustments.index'));
});

test('no milk nav appears for a user with no milk permissions at all', function () {
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain(route('milk.production.index'))
        ->and($html)->not->toContain(route('milk.usage.index'))
        ->and($html)->not->toContain(route('milk.reconciliation'));
});
