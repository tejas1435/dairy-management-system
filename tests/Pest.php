<?php

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\CustomerPreference;
use App\Models\Farm;
use App\Models\FinancialAccount;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\User;
use App\Support\RoleCatalog;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalesChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the MySQL test schema (see docs/DECISIONS.md D2)
| and are wrapped in a transaction that is rolled back after each test, so
| every test starts from a migrated but empty database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Seeds the permission catalogue and the default roles.
 *
 * Spatie caches permissions in memory, so the cache is cleared first: without
 * this a test can see the previous test's rolled-back permission rows.
 */
function seedAuthorization(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** Creates the one business and its primary farm. */
function seedBusiness(): Business
{
    $business = Business::factory()->create(['name' => 'Test Dairy']);

    Farm::factory()->for($business)->primary()->create([
        'name' => 'Main Farm',
        'code' => 'MAIN',
    ]);

    return $business;
}

/**
 * A user holding exactly the given permissions, granted through a throwaway
 * role. Permissions are never assigned to users directly, matching production.
 *
 * @param  array<int, string>  $permissions
 */
function userWithPermissions(array $permissions, array $attributes = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('Test Role '.fake()->unique()->numerify('####'), 'web');
    $role->syncPermissions($permissions);

    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/** A user with no permissions at all: signed in, but allowed nothing. */
function userWithoutPermissions(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

/**
 * Seeds the Phase 2 master data a financial test needs: payment methods,
 * expense categories and the three system sales channels.
 *
 * Requires seedBusiness() first, because sales channels belong to a business.
 */
function seedPhase2Masters(): void
{
    (new PaymentMethodSeeder)->run();
    (new ExpenseCategorySeeder)->run();
    (new SalesChannelSeeder)->run();
}

/**
 * A cash account and a bank account for the given business.
 *
 * @return array{cash: FinancialAccount, bank: FinancialAccount}
 */
function seedAccounts(Business $business, string $cashOpening = '0.00', string $bankOpening = '0.00'): array
{
    return [
        'cash' => FinancialAccount::factory()->for($business)->cash()
            ->withOpeningBalance($cashOpening)->create(['name' => 'Cash']),
        'bank' => FinancialAccount::factory()->for($business)->bank()
            ->withOpeningBalance($bankOpening)->create(['name' => 'Main Bank Account']),
    ];
}

/**
 * A direct customer with an active preference for each given milk type.
 *
 * Requires the sales channels to be seeded (`seedPhase2Masters()`). Reminders default
 * to a plausible litre each way; tests that care about the figures set them
 * explicitly, and tests that care about them *not* being used set them deliberately
 * high.
 *
 * @param  array<int, MilkType>  $milkTypes
 */
function directCustomer(
    Business $business,
    array $milkTypes = [MilkType::Cow],
    array $attributes = [],
): Buyer {
    $customer = Buyer::factory()->directCustomer($business)->create($attributes);

    foreach ($milkTypes as $milkType) {
        CustomerPreference::factory()->for($customer)->milkType($milkType)->create();
    }

    return $customer->load('preferences');
}

/**
 * A direct customer taking one milk type, with reminder figures that matter.
 *
 * The daily entry tests need reminders set to specific values so the assertion
 * "the input is empty even though the reminder says 2.000" can be made at all.
 * Returned with preferences loaded, so the sale actions see them.
 */
function customerWithReminder(
    Business $business,
    MilkType $milkType,
    string $morning,
    string $evening,
    array $attributes = [],
): Buyer {
    $customer = Buyer::factory()->directCustomer($business)->create($attributes);

    CustomerPreference::factory()->for($customer)->milkType($milkType)
        ->reminders($morning, $evening)->create();

    return $customer->load('preferences');
}

/**
 * Production for a shift, so a sale has milk to draw on.
 *
 * Most Phase 4 sale tests need production to exist first — that is the Phase 3 rule
 * they inherit — so it is worth one helper rather than four lines each time.
 */
function seedProductionFor(
    Farm $farm,
    string $date,
    string $cow = '100.000',
    string $buffalo = '100.000',
): void {
    /*
     * Idempotent: production is unique on farm, date and shift, so a test that seeds
     * overlapping date ranges twice would otherwise fail on the constraint rather
     * than on its subject.
     */
    foreach (Shift::cases() as $shift) {
        MilkProduction::query()->updateOrCreate(
            [
                'farm_id' => $farm->getKey(),
                'production_date' => $date,
                'shift' => $shift->value,
            ],
            [
                'cow_milk_quantity' => $cow,
                'buffalo_milk_quantity' => $buffalo,
            ],
        );
    }
}

/**
 * A business default price for each milk type, so the resolver finds one.
 *
 * Idempotent, because the price period unique key forbids two rules starting on the
 * same day and a test that calls this from both `beforeEach` and its own body would
 * otherwise fail on a constraint rather than on its subject.
 */
function seedDefaultPrices(Business $business, string $cow = '70.00', string $buffalo = '85.00'): void
{
    foreach ([MilkType::Cow->value => $cow, MilkType::Buffalo->value => $buffalo] as $milkType => $rate) {
        MilkPriceRule::query()->updateOrCreate(
            [
                'business_id' => $business->getKey(),
                'milk_type' => $milkType,
                'effective_from' => '2020-01-01',
            ],
            ['rate' => $rate, 'effective_to' => null],
        );
    }
}

/**
 * Asserts two money values are equal as decimals.
 *
 * Money is compared with bccomp on strings, never with == on floats: 0.1 + 0.2
 * is not 0.3 in binary floating point, and a test that tolerates that drift
 * would pass while the books are wrong.
 */
function expectMoney(string|float|int|null $actual, string $expected, string $message = ''): void
{
    $actualString = number_format((float) $actual, 2, '.', '');

    expect(bccomp($actualString, $expected, 2))->toBe(
        0,
        $message !== '' ? $message : "Expected {$expected}, got {$actualString}"
    );
}

/** A Super Admin, which the authorisation gate grants everything. */
function superAdmin(array $attributes = []): User
{
    seedAuthorization();

    $user = User::factory()->create($attributes);
    $user->assignRole(RoleCatalog::SUPER_ADMIN);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}
