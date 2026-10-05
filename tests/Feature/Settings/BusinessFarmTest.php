<?php

use App\Actions\Farms\SetFarmActiveState;
use App\Actions\Farms\SetPrimaryFarm;
use App\Enums\DateFormat;
use App\Enums\Locale;
use App\Models\Business;
use App\Models\Farm;
use App\Services\BusinessContext;
use App\Support\PermissionCatalog;
use Database\Seeders\BusinessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedAuthorization();
});

/*
|--------------------------------------------------------------------------
| Business
|--------------------------------------------------------------------------
*/

test('the seeded business has the regional defaults the specification requires', function () {
    $this->seed(BusinessSeeder::class);

    $business = app(BusinessContext::class)->business();

    expect($business->currency)->toBe('INR')
        ->and($business->timezone)->toBe('Asia/Kolkata')
        ->and($business->date_format)->toBe(DateFormat::DayMonthYearDashed)
        ->and($business->date_format->label())->toBe('DD-MM-YYYY')
        ->and($business->default_locale)->toBe(Locale::English)
        ->and($business->is_active)->toBeTrue();
});

test('settings are refused without settings.manage', function (string $routeName) {
    seedBusiness();
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->get(route($routeName))->assertForbidden();
})->with([
    'settings.business.edit',
    'settings.farms.index',
    'settings.farms.create',
]);

test('an unauthorised user cannot change business settings by posting directly', function () {
    $business = seedBusiness();
    $user = userWithPermissions([PermissionCatalog::DASHBOARD_VIEW]);

    $this->actingAs($user)->put(route('settings.business.update'), [
        'name' => 'Hijacked',
        'currency' => 'INR',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'd-m-Y',
        'default_locale' => 'en',
    ])->assertForbidden();

    expect($business->refresh()->name)->not->toBe('Hijacked');
});

test('an authorised user can update the business', function () {
    $business = seedBusiness();
    $admin = userWithPermissions([PermissionCatalog::SETTINGS_MANAGE]);

    $this->actingAs($admin)->put(route('settings.business.update'), [
        'name' => 'Patel Dairy',
        'legal_name' => 'Patel Dairy Enterprises',
        'mobile' => '9876543210',
        'email' => 'contact@pateldairy.test',
        'address' => 'Village Road, Anand',
        'currency' => 'INR',
        'timezone' => 'Asia/Kolkata',
        'date_format' => DateFormat::DayMonthYearSlashed->value,
        'default_locale' => Locale::Gujarati->value,
        'is_active' => '1',
    ])->assertRedirect(route('settings.business.edit'));

    $business->refresh();

    expect($business->name)->toBe('Patel Dairy')
        ->and($business->date_format)->toBe(DateFormat::DayMonthYearSlashed)
        ->and($business->default_locale)->toBe(Locale::Gujarati);
});

test('an unsupported locale or timezone is rejected', function () {
    seedBusiness();
    $admin = userWithPermissions([PermissionCatalog::SETTINGS_MANAGE]);

    $this->actingAs($admin)->put(route('settings.business.update'), [
        'name' => 'Patel Dairy',
        'currency' => 'USD',
        'timezone' => 'Mars/Olympus',
        'date_format' => 'nonsense',
        'default_locale' => 'fr',
    ])->assertSessionHasErrors(['currency', 'timezone', 'date_format', 'default_locale']);
});

/*
|--------------------------------------------------------------------------
| Farms
|--------------------------------------------------------------------------
*/

test('a farm belongs to its business', function () {
    $business = seedBusiness();
    $farm = $business->farms()->first();

    expect($farm->business->is($business))->toBeTrue()
        ->and($business->farms)->toHaveCount(1);
});

test('the primary farm resolver returns the intended farm', function () {
    $business = seedBusiness();
    Farm::factory()->for($business)->create(['code' => 'SEC']);

    $context = app(BusinessContext::class);

    expect($context->primaryFarm()->code)->toBe('MAIN')
        ->and($context->primaryFarmId())->toBe($context->primaryFarm()->id);
});

test('the resolver explains itself rather than returning null when nothing is seeded', function () {
    app(BusinessContext::class)->forget();

    expect(fn () => app(BusinessContext::class)->business())
        ->toThrow(RuntimeException::class);

    expect(app(BusinessContext::class)->businessOrNull())->toBeNull();
});

test('a business may hold many non primary farms', function () {
    // The guard must constrain primaries only. A naive unique index on
    // (business_id, is_primary) would wrongly forbid a second non-primary farm.
    $business = seedBusiness();

    Farm::factory()->for($business)->create(['code' => 'A1']);
    Farm::factory()->for($business)->create(['code' => 'B1']);
    Farm::factory()->for($business)->create(['code' => 'C1']);

    expect($business->farms()->count())->toBe(4)
        ->and($business->farms()->where('is_primary', false)->count())->toBe(3);
});

test('the database refuses a second primary farm for the same business', function () {
    $business = seedBusiness();

    expect(fn () => Farm::factory()->for($business)->primary()->create(['code' => 'DUP']))
        ->toThrow(QueryException::class);
});

test('changing the primary farm leaves exactly one primary', function () {
    $business = seedBusiness();
    $original = $business->farms()->first();
    $replacement = Farm::factory()->for($business)->create(['code' => 'NEW']);

    app(SetPrimaryFarm::class)->handle($replacement);

    expect($replacement->refresh()->is_primary)->toBeTrue()
        ->and($original->refresh()->is_primary)->toBeFalse()
        ->and($business->farms()->where('is_primary', true)->count())->toBe(1)
        ->and(app(BusinessContext::class)->primaryFarm()->code)->toBe('NEW');
});

test('promoting a farm through the settings screen demotes the previous one', function () {
    $business = seedBusiness();
    $admin = userWithPermissions([PermissionCatalog::SETTINGS_MANAGE]);
    $original = $business->farms()->first();
    $replacement = Farm::factory()->for($business)->create(['code' => 'NEW']);

    $this->actingAs($admin)->put(route('settings.farms.primary', $replacement))->assertRedirect();

    expect($replacement->refresh()->is_primary)->toBeTrue()
        ->and($original->refresh()->is_primary)->toBeFalse();
});

test('an inactive farm cannot be made primary', function () {
    $business = seedBusiness();
    $inactive = Farm::factory()->for($business)->inactive()->create(['code' => 'OLD']);

    expect(fn () => app(SetPrimaryFarm::class)->handle($inactive))
        ->toThrow(ValidationException::class);

    expect($inactive->refresh()->is_primary)->toBeFalse();
});

test('the primary farm cannot be deactivated', function () {
    // Operational screens resolve it automatically, so disabling it would leave
    // production and sales with no farm to attach to.
    $business = seedBusiness();
    $primary = $business->farms()->first();

    expect(fn () => app(SetFarmActiveState::class)->handle($primary, false))
        ->toThrow(ValidationException::class);

    expect($primary->refresh()->is_active)->toBeTrue();
});

test('a non primary farm can be deactivated and reactivated', function () {
    $business = seedBusiness();
    $admin = userWithPermissions([PermissionCatalog::SETTINGS_MANAGE]);
    $secondary = Farm::factory()->for($business)->create(['code' => 'SEC']);

    $this->actingAs($admin)->put(route('settings.farms.status.update', $secondary), ['is_active' => 0]);
    expect($secondary->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->put(route('settings.farms.status.update', $secondary), ['is_active' => 1]);
    expect($secondary->refresh()->is_active)->toBeTrue();
});

test('farm codes are unique within a business', function () {
    $business = seedBusiness();
    $admin = userWithPermissions([PermissionCatalog::SETTINGS_MANAGE]);

    $this->actingAs($admin)->post(route('settings.farms.store'), [
        'name' => 'Duplicate',
        'code' => 'MAIN',
    ])->assertSessionHasErrors('code');
});

test('a farm created through settings is never primary by default', function () {
    $business = seedBusiness();
    $admin = userWithPermissions([PermissionCatalog::SETTINGS_MANAGE]);

    $this->actingAs($admin)->post(route('settings.farms.store'), [
        'name' => 'Second Farm',
        'code' => 'sec-1',
        'is_active' => '1',
    ])->assertRedirect(route('settings.farms.index'));

    $farm = Farm::query()->where('code', 'SEC-1')->firstOrFail();

    expect($farm->is_primary)->toBeFalse()
        ->and($farm->business_id)->toBe($business->id);
});

test('the business seeder is idempotent', function () {
    $this->seed(BusinessSeeder::class);
    $this->seed(BusinessSeeder::class);

    expect(Business::query()->count())->toBe(1)
        ->and(Farm::query()->count())->toBe(1);
});
