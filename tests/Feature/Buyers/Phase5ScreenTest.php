<?php

use App\Actions\Milk\RecordChannelSale;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkSale;
use App\Models\User;
use App\Support\RoleCatalog;
use Spatie\Permission\PermissionRegistrar;

/*
 * Every Phase 5 screen renders, in all three locales, with real records rather than
 * empty states — and the authorisation on each one is the buyer's own channel family
 * rather than a role name.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();
    seedAccounts($this->business, cashOpening: '100000.00');

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '1000.000', buffalo: '1000.000');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);
    $this->vendor = Buyer::factory()->vendor($this->business)->create(['name' => 'Patel Dairy']);
    $this->shop = Buyer::factory()->inCustomChannel($this->business)->create(['name' => 'Corner Sweet Shop']);

    BuyerPriceRule::factory()->for($this->vendor)->forType(MilkType::Cow)
        ->rate('74.00')->period('2026-10-01')->create();

    $this->actingAs(superAdmin());

    // One sale through each workflow, so no page is an empty state.
    $record = app(RecordChannelSale::class);

    $record->handle(
        buyer: $this->mandali, source: SaleSource::MandaliDelivery, date: $this->date,
        shift: Shift::Morning, milkType: MilkType::Cow, quantity: '50.000', rate: '72.00',
        attributes: ['fat_percentage' => '4.50', 'snf_percentage' => '8.50', 'notes' => 'Morning collection'],
    );

    $record->handle(
        buyer: $this->vendor, source: SaleSource::VendorSale, date: $this->date,
        shift: Shift::Morning, milkType: MilkType::Cow, quantity: '20.000', rate: null,
    );

    $record->handle(
        buyer: $this->shop, source: SaleSource::GenericSale, date: $this->date,
        shift: Shift::Evening, milkType: MilkType::Buffalo, quantity: '5.000', rate: '95.00',
    );

    $this->settlement = app(CreateBuyerSettlement::class)
        ->handle($this->mandali, '2026-10-01', '2026-10-31', '3700.00');
});

/*
|--------------------------------------------------------------------------
| A. Every page renders
|--------------------------------------------------------------------------
*/

test('every Phase 5 page renders with real records', function (string $route, array $params) {
    $this->get(route($route, $params))->assertOk();
})->with([
    'mandali list' => ['mandalis.index', []],
    'vendor list' => ['vendors.index', []],
    'other-buyer list' => ['other-buyers.index', []],
    'mandali deliveries' => ['milk.mandali-deliveries.index', []],
    'record a delivery' => ['milk.mandali-deliveries.create', []],
    'vendor sales' => ['milk.vendor-sales.index', []],
    'record a vendor sale' => ['milk.vendor-sales.create', []],
    'other sales' => ['milk.other-sales.index', []],
    'record an other sale' => ['milk.other-sales.create', []],
]);

test('the Mandali profile renders with its ledger and settlements', function () {
    $this->get(route('mandalis.show', $this->mandali))
        ->assertOk()
        ->assertSee($this->mandali->name)
        ->assertSee(__('buyers.ledger.title'))
        ->assertSee(__('buyers.ledger.outstanding'))
        ->assertSee(__('buyers.settlement.title'))
        // 50.000 L at 72.00.
        ->assertSee('3,600.00');
});

test('the vendor profile renders without a settlement section', function () {
    $html = $this->get(route('vendors.show', $this->vendor))->assertOk()->getContent();

    expect($html)->toContain(__('buyers.ledger.title'))
        // Only a Mandali has settlements, so the section is absent rather than empty.
        ->and($html)->not->toContain(__('buyers.settlement.create'));
});

test('the settlement screens render through the whole lifecycle', function () {
    $this->get(route('mandalis.settlements.index', $this->mandali))->assertOk();
    $this->get(route('mandalis.settlements.create', $this->mandali))->assertOk();

    // Draft: live figures and the finalize control.
    $this->get(route('mandalis.settlements.show', [$this->mandali, $this->settlement]))
        ->assertOk()
        ->assertSee(__('buyers.settlement.draft_help'))
        ->assertSee(__('buyers.settlement.finalize'));

    app(FinalizeBuyerSettlement::class)->handle($this->settlement);

    // Finalized: snapshots, the difference and no finalize control.
    $this->get(route('mandalis.settlements.show', [$this->mandali, $this->settlement->refresh()]))
        ->assertOk()
        ->assertSee(__('buyers.settlement_statuses.finalized'))
        ->assertSee(__('buyers.settlement.difference_recorded'))
        ->assertDontSee(__('buyers.settlement.draft_help'));
});

test('the sale edit screen renders for each workflow', function (string $source, string $route) {
    $sale = MilkSale::query()->where('source', $source)->firstOrFail();

    $this->get(route($route, $sale))
        ->assertOk()
        ->assertSee(__('buyers.sale.edit'))
        ->assertSee(__('buyers.sale.cancel'));
})->with([
    'mandali' => [SaleSource::MandaliDelivery->value, 'milk.mandali-deliveries.edit'],
    'vendor' => [SaleSource::VendorSale->value, 'milk.vendor-sales.edit'],
    'other' => [SaleSource::GenericSale->value, 'milk.other-sales.edit'],
]);

test('a sale cannot be edited through another workflow screen', function () {
    $mandaliSale = MilkSale::query()
        ->where('source', SaleSource::MandaliDelivery->value)->firstOrFail();

    // The fat fields and the rate rules differ per workflow, so crossing them is a 404.
    $this->get(route('milk.vendor-sales.edit', $mandaliSale))->assertNotFound();
    $this->get(route('milk.other-sales.edit', $mandaliSale))->assertNotFound();
});

test('a buyer from another channel is not reachable through the wrong profile', function () {
    $this->get(route('mandalis.show', $this->vendor))->assertNotFound();
    $this->get(route('vendors.show', $this->mandali))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| B. The Mandali fields are on the Mandali screen and nowhere else
|--------------------------------------------------------------------------
*/

test('fat and SNF appear on the Mandali form and not on the others', function () {
    $mandali = $this->get(route('milk.mandali-deliveries.create'))->assertOk()->getContent();
    $vendor = $this->get(route('milk.vendor-sales.create'))->assertOk()->getContent();
    $other = $this->get(route('milk.other-sales.create'))->assertOk()->getContent();

    expect($mandali)->toContain('name="fat_percentage"')
        ->toContain('name="snf_percentage"')
        ->toContain('name="slip"')
        // The rule, stated on the screen where it matters.
        ->toContain(__('buyers.sale.help.fat_snf'));

    foreach ([$vendor, $other] as $html) {
        expect($html)->not->toContain('name="fat_percentage"')
            ->not->toContain('name="snf_percentage"')
            ->not->toContain('name="slip"');
    }
});

test('each form explains its own rate rule', function () {
    $this->get(route('milk.mandali-deliveries.create'))->assertOk()
        ->assertSee(__('buyers.sale.help.manual_rate'));

    $this->get(route('milk.vendor-sales.create'))->assertOk()
        ->assertSee(__('buyers.sale.help.vendor_rate'));

    $this->get(route('milk.other-sales.create'))->assertOk()
        ->assertSee(__('buyers.sale.help.generic_rate'));
});

test('the other-sales form lists only custom-channel buyers', function () {
    $html = $this->get(route('milk.other-sales.create'))->assertOk()->getContent();

    expect($html)->toContain('Corner Sweet Shop')
        ->not->toContain('Shree Dairy Mandali')
        ->not->toContain('Patel Dairy');
});

/*
|--------------------------------------------------------------------------
| C. Authorisation
|--------------------------------------------------------------------------
*/

test('the Mandali and vendor lists need their own channel permission', function () {
    $this->actingAs(userWithPermissions(['mandali.view']));
    $this->get(route('mandalis.index'))->assertOk();
    $this->get(route('vendors.index'))->assertForbidden();

    $this->actingAs(userWithPermissions(['vendor.view']));
    $this->get(route('vendors.index'))->assertOk();
    $this->get(route('mandalis.index'))->assertForbidden();
});

test('the sale screens need the milk sale permissions', function () {
    $this->actingAs(userWithoutPermissions());
    $this->get(route('milk.mandali-deliveries.index'))->assertForbidden();

    $this->actingAs(userWithPermissions(['milk.sale.view']));
    $this->get(route('milk.mandali-deliveries.index'))->assertOk();
    // Viewing does not imply recording.
    $this->get(route('milk.mandali-deliveries.create'))->assertForbidden();
});

test('settlements need the settlement permission and a Mandali', function () {
    $this->actingAs(userWithPermissions(['mandali.view']));
    $this->get(route('mandalis.settlements.index', $this->mandali))->assertForbidden();

    $this->actingAs(userWithPermissions(['mandali.view', 'mandali.settlement.manage']));
    $this->get(route('mandalis.settlements.index', $this->mandali))->assertOk();

    // A vendor has no settlements, and the policy says so rather than 404ing late.
    $this->get(route('mandalis.settlements.index', $this->vendor))->assertForbidden();
});

test('the seeded roles reach the Phase 5 screens as the matrix says', function (string $role, bool $mandaliList, bool $canRecord, bool $statement) {
    $user = User::factory()->create();
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->refresh());

    $mandaliList
        ? $this->get(route('mandalis.index'))->assertOk()
        : $this->get(route('mandalis.index'))->assertForbidden();

    $canRecord
        ? $this->get(route('milk.mandali-deliveries.create'))->assertOk()
        : $this->get(route('milk.mandali-deliveries.create'))->assertForbidden();

    // The period statement reads the same records as the profile, so it is the same
    // permission: anyone who may open a Mandali may read its statement.
    $statement
        ? $this->get(route('mandalis.statement', $this->mandali))->assertOk()
        : $this->get(route('mandalis.statement', $this->mandali))->assertForbidden();
})->with([
    'Super Admin' => [RoleCatalog::SUPER_ADMIN, true, true, true],
    'Owner' => [RoleCatalog::OWNER, true, true, true],
    'Manager' => [RoleCatalog::MANAGER, true, true, true],
    'Data Operator' => [RoleCatalog::DATA_OPERATOR, true, true, true],
    'Accountant' => [RoleCatalog::ACCOUNTANT, true, false, true],
    'Viewer' => [RoleCatalog::VIEWER, true, false, true],
]);

test('Phase 5 authorisation never branches on a role name', function () {
    $source = '';

    foreach ([
        'Http/Controllers/Buyers/ChannelBuyerController.php',
        'Http/Controllers/Buyers/MandaliController.php',
        'Http/Controllers/Buyers/VendorController.php',
        'Http/Controllers/Buyers/OtherBuyerController.php',
        'Http/Controllers/Buyers/BuyerSettlementController.php',
        'Http/Controllers/Buyers/BuyerPaymentController.php',
        'Http/Controllers/Milk/ChannelSaleController.php',
        'Actions/Milk/RecordChannelSale.php',
        'Actions/Milk/UpdateChannelSale.php',
        'Actions/Settlements/FinalizeBuyerSettlement.php',
        'Services/Buyers/SettlementGuard.php',
    ] as $path) {
        $source .= file_get_contents(app_path($path));
    }

    foreach (['hasRole', 'hasAnyRole', 'hasAllRoles', 'Super Admin', 'Accountant', 'Data Operator'] as $needle) {
        expect($source)->not->toContain($needle);
    }
});

/*
|--------------------------------------------------------------------------
| D. Navigation and localisation
|--------------------------------------------------------------------------
*/

test('the sidebar links to every Phase 5 screen that exists', function () {
    $html = $this->get(route('milk.production.index'))->assertOk()->getContent();

    foreach ([
        route('mandalis.index'),
        route('vendors.index'),
        route('other-buyers.index'),
        route('milk.mandali-deliveries.index'),
        route('milk.vendor-sales.index'),
        route('milk.other-sales.index'),
    ] as $url) {
        expect($html)->toContain($url);
    }

    expect(strtolower($html))->not->toContain('coming soon');
});

test('each trade profile links to its own sale workflow with the buyer chosen', function (string $which, string $routePrefix, string $saleRoute) {
    $buyer = $this->{$which};

    $html = $this->get(route($routePrefix.'.show', $buyer))->assertOk()->getContent();

    expect($html)->toContain(e(route($saleRoute, ['buyer' => $buyer->id])));

    // And the form arrives with that buyer selected, so the operator does not pick it
    // out of a list they just came from.
    $form = $this->get(route($saleRoute, ['buyer' => $buyer->id]))->assertOk()->getContent();

    expect($form)->toMatch('/value="'.$buyer->id.'"\s*\n?\s*selected/');
})->with([
    'mandali' => ['mandali', 'mandalis', 'milk.mandali-deliveries.create'],
    'vendor' => ['vendor', 'vendors', 'milk.vendor-sales.create'],
    'custom channel' => ['shop', 'other-buyers', 'milk.other-sales.create'],
]);

test('a buyer from another channel in the query string selects nobody', function () {
    // The hint is a hint. The posted id is resolved through the workflow's own buyer
    // query, so a Mandali id on the vendor form cannot preselect or save.
    $form = $this->get(route('milk.vendor-sales.create', ['buyer' => $this->mandali->id]))
        ->assertOk()
        ->getContent();

    expect($form)->not->toContain('Shree Dairy Mandali')
        ->and($form)->not->toMatch('/value="'.$this->mandali->id.'"\s*\n?\s*selected/');
});

test('the profile hides the sale link from a user who may not record one', function () {
    $this->actingAs(userWithPermissions(['mandali.view']));

    $html = $this->get(route('mandalis.show', $this->mandali))->assertOk()->getContent();

    expect($html)->not->toContain(e(route('milk.mandali-deliveries.create', ['buyer' => $this->mandali->id])));
});

test('no Phase 6 route or table exists yet', function () {
    foreach (['animals.index', 'animals.create', 'employees.index', 'payroll.index', 'reports.index'] as $name) {
        expect(app('router')->getRoutes()->getByName($name))->toBeNull();
    }

    foreach (['animals', 'animal_events', 'employees', 'payrolls', 'notifications'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

test('the Phase 5 screens render in Gujarati and Hindi with no raw keys', function (string $locale) {
    $user = superAdmin(['locale' => $locale]);

    $pages = [
        route('mandalis.index'),
        route('mandalis.show', $this->mandali),
        route('mandalis.statement', $this->mandali),
        route('vendors.index'),
        route('vendors.show', $this->vendor),
        route('other-buyers.index'),
        route('other-buyers.show', $this->shop),
        route('milk.mandali-deliveries.index'),
        route('milk.mandali-deliveries.create'),
        route('milk.vendor-sales.create'),
        route('milk.other-sales.create'),
        route('mandalis.settlements.index', $this->mandali),
        route('mandalis.settlements.show', [$this->mandali, $this->settlement]),
    ];

    foreach ($pages as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        // No untranslated dotted key leaked into the markup.
        expect($html)->not->toMatch('/\b(buyers|milk|nav|app|customers)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');
    }

    // And the Phase 5 vocabulary really is translated, not copied English.
    expect(__('buyers.mandali.title', [], $locale))->not->toBe(__('buyers.mandali.title', [], 'en'))
        ->and(__('buyers.settlement.title', [], $locale))->not->toBe(__('buyers.settlement.title', [], 'en'))
        ->and(__('buyers.settlement_statuses.draft', [], $locale))->not->toBe('Draft');
})->with(['gu', 'hi']);
