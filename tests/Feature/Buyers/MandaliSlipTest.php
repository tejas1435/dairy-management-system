<?php

use App\Enums\AuditAction;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\MilkSale;
use App\Services\AuditLogger;
use App\Services\Milk\MilkSaleSlips;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The Mandali collection slip.
 *
 * A commercial document between the farm and a dairy, attached to the delivery it
 * belongs to. Three things have to hold: the stored file is not reachable without
 * authorisation, nothing the uploader chose reaches the filesystem, and the file
 * follows the record through replacement, removal and withdrawal.
 */

beforeEach(function (): void {
    Storage::fake(MilkSaleSlips::DISK);

    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '1000.000', buffalo: '1000.000');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);

    $this->actingAs(superAdmin());

    $this->form = fn (array $overrides = []): array => array_merge([
        'buyer_id' => $this->mandali->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '50.000',
        'unit_rate' => '72.00',
        'fat_percentage' => '4.50',
        'snf_percentage' => '8.50',
    ], $overrides);

    $this->record = function (array $overrides = []): MilkSale {
        $this->post(route('milk.mandali-deliveries.store'), ($this->form)($overrides))
            ->assertRedirect();

        return MilkSale::query()->latest('id')->firstOrFail();
    };
});

/*
|--------------------------------------------------------------------------
| A. What reaches the filesystem
|--------------------------------------------------------------------------
*/

test('an uploaded slip is stored privately under a name the uploader did not choose', function () {
    $sale = ($this->record)([
        'slip' => UploadedFile::fake()->create('../../september statement.pdf', 120, 'application/pdf'),
    ]);

    expect($sale->slip_path)->not->toBeNull();

    Storage::disk(MilkSaleSlips::DISK)->assertExists($sale->slip_path);

    // Inside the one directory, with a random basename and the file's own extension.
    expect($sale->slip_path)->toStartWith(MilkSaleSlips::DIRECTORY.'/')
        ->and($sale->slip_path)->toEndWith('.pdf')
        ->and(basename($sale->slip_path))->not->toContain('statement')
        ->and($sale->slip_path)->not->toContain('..');

    // The name the browser sent survives as a label only, with any directory part gone.
    expect($sale->slip_name)->toBe('september statement.pdf');
});

test('the stored file is not on the public disk and has no url', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->image('slip.jpg')]);

    expect(MilkSaleSlips::DISK)->toBe('local')
        ->and(config('filesystems.disks.local.root'))->toContain('private')
        // Nothing in the application hands out a storage URL for a slip.
        ->and(config('filesystems.disks.local.visibility', 'private'))->not->toBe('public');

    Storage::disk('public')->assertMissing($sale->slip_path);
});

test('a file that is not a slip is refused', function (string $name, int $kilobytes, string $mime) {
    $this->post(route('milk.mandali-deliveries.store'), ($this->form)([
        'slip' => UploadedFile::fake()->create($name, $kilobytes, $mime),
    ]))->assertSessionHasErrors('slip');

    expect(MilkSale::query()->count())->toBe(0);
})->with([
    'an executable' => ['payload.exe', 10, 'application/x-msdownload'],
    'a spreadsheet' => ['book.xlsx', 10, 'application/vnd.ms-excel'],
    'something above the size limit' => ['huge.pdf', MilkSaleSlips::MAX_KILOBYTES + 1, 'application/pdf'],
    'a pdf that is really not one' => ['claims.pdf', 10, 'text/html'],
]);

/*
|--------------------------------------------------------------------------
| B. Who may read it
|--------------------------------------------------------------------------
*/

test('a slip downloads for a user who may view the delivery', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);

    $response = $this->get(route('milk.mandali-deliveries.slip', $sale));

    $response->assertOk()
        ->assertDownload('slip.pdf');
});

test('a slip is refused to a user who may not view the Mandali', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);

    // Seeing milk sales is not seeing this dairy's documents.
    $this->actingAs(userWithPermissions(['milk.sale.view']));
    $this->get(route('milk.mandali-deliveries.slip', $sale))->assertForbidden();

    $this->actingAs(userWithoutPermissions());
    $this->get(route('milk.mandali-deliveries.slip', $sale))->assertForbidden();

    $this->actingAs(userWithPermissions(['milk.sale.view', 'mandali.view']));
    $this->get(route('milk.mandali-deliveries.slip', $sale))->assertOk();
});

test('a sale with no slip is a 404 rather than an empty download', function () {
    $sale = ($this->record)();

    $this->get(route('milk.mandali-deliveries.slip', $sale))->assertNotFound();
});

test('a slip whose file has gone missing is a 404 rather than a server error', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);

    Storage::disk(MilkSaleSlips::DISK)->delete($sale->slip_path);

    $this->get(route('milk.mandali-deliveries.slip', $sale))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| C. The file follows the record
|--------------------------------------------------------------------------
*/

test('replacing a slip stores the new file and deletes the old one', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('first.pdf', 20, 'application/pdf')]);
    $first = $sale->slip_path;

    $this->put(route('milk.mandali-deliveries.update', $sale), [
        'quantity' => '50.000',
        'unit_rate' => '72.00',
        'slip' => UploadedFile::fake()->create('second.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $sale->refresh();

    expect($sale->slip_path)->not->toBe($first)
        ->and($sale->slip_name)->toBe('second.pdf');

    Storage::disk(MilkSaleSlips::DISK)->assertExists($sale->slip_path);
    Storage::disk(MilkSaleSlips::DISK)->assertMissing($first);
});

test('a correction that does not mention the slip keeps it', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);
    $path = $sale->slip_path;

    $this->put(route('milk.mandali-deliveries.update', $sale), [
        'quantity' => '45.000',
        'unit_rate' => '72.00',
    ])->assertRedirect();

    expect($sale->refresh()->slip_path)->toBe($path);
    Storage::disk(MilkSaleSlips::DISK)->assertExists($path);
});

test('removing a slip clears the columns and deletes the file', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);
    $path = $sale->slip_path;

    $this->put(route('milk.mandali-deliveries.update', $sale), [
        'quantity' => '50.000',
        'unit_rate' => '72.00',
        'remove_slip' => '1',
    ])->assertRedirect();

    $sale->refresh();

    expect($sale->slip_path)->toBeNull()
        ->and($sale->slip_name)->toBeNull();

    Storage::disk(MilkSaleSlips::DISK)->assertMissing($path);
});

test('withdrawing a delivery keeps its slip, because the record is kept too', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);
    $path = $sale->slip_path;

    $this->put(route('milk.mandali-deliveries.cancel', $sale), [
        'cancellation_reason' => 'Recorded against the wrong Mandali',
    ])->assertRedirect();

    /*
     * A withdrawn sale is not a deleted one (D21): it stays in the history with its
     * reason, and the document that supported it stays with it. Deleting the file
     * would make a withdrawn delivery unauditable precisely when somebody wants to
     * know why it was withdrawn.
     */
    expect($sale->refresh()->slip_path)->toBe($path);
    Storage::disk(MilkSaleSlips::DISK)->assertExists($path);

    // And it is still readable by someone who may see the delivery.
    $this->get(route('milk.mandali-deliveries.slip', $sale))->assertOk();
});

/*
|--------------------------------------------------------------------------
| D. Only this workflow has slips
|--------------------------------------------------------------------------
*/

test('no other sale workflow accepts or exposes a slip', function () {
    $vendor = Buyer::factory()->vendor($this->business)->create();

    $this->post(route('milk.vendor-sales.store'), [
        'buyer_id' => $vendor->id,
        'sale_date' => $this->date,
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'quantity' => '10.000',
        'slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'),
    ])->assertSessionHasErrors('slip');

    /*
     * Refused rather than quietly dropped. A slip is the Mandali's own weighing
     * document; a vendor sale has no such thing, so an upload against one means the
     * operator is on the wrong screen and should be told.
     */
    expect(MilkSale::query()->where('source', SaleSource::VendorSale->value)->count())->toBe(0)
        ->and(Storage::disk(MilkSaleSlips::DISK)->allFiles())->toBeEmpty();

    // And no route serves one for the other workflows.
    foreach (['milk.vendor-sales.slip', 'milk.other-sales.slip'] as $name) {
        expect(app('router')->getRoutes()->getByName($name))->toBeNull();
    }
});

test('a Mandali slip cannot be fetched through another workflow route', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);

    // The only slip route is the Mandali one, and it refuses a sale from elsewhere.
    expect(app('router')->getRoutes()->getByName('milk.mandali-deliveries.slip'))->not->toBeNull();

    $vendor = Buyer::factory()->vendor($this->business)->create();
    $vendorSale = MilkSale::factory()->create([
        'farm_id' => $this->farm->id,
        'buyer_id' => $vendor->id,
        'sales_channel_id' => $vendor->sales_channel_id,
        'source' => SaleSource::VendorSale->value,
        'sale_date' => $this->date,
        'slip_path' => $sale->slip_path,
        'slip_name' => 'borrowed.pdf',
    ]);

    $this->get(route('milk.mandali-deliveries.slip', $vendorSale))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| E. The audit log records the slip without recording the file
|--------------------------------------------------------------------------
*/

test('attaching, replacing and removing a slip each leave an audit record', function () {
    $sale = ($this->record)();

    $attach = fn (string $name) => $this->put(route('milk.mandali-deliveries.update', $sale), [
        'quantity' => '50.000',
        'unit_rate' => '72.00',
        'slip' => UploadedFile::fake()->create($name, 20, 'application/pdf'),
    ])->assertRedirect();

    $countAudits = fn (): int => AuditLog::query()
        ->where('auditable_type', 'milk_sale')
        ->where('auditable_id', $sale->getKey())
        ->where('action', AuditAction::Updated->value)
        ->count();

    $attach('first.pdf');
    expect($countAudits())->toBe(1);

    // A replacement changes nothing else about the sale, so without the slip fields
    // in the payload the diff would be empty and no record would be written at all.
    $attach('second.pdf');
    expect($countAudits())->toBe(2);

    $this->put(route('milk.mandali-deliveries.update', $sale), [
        'quantity' => '50.000',
        'unit_rate' => '72.00',
        'remove_slip' => '1',
    ])->assertRedirect();

    expect($countAudits())->toBe(3);
});

test('no audit record contains the stored path', function () {
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);

    $this->put(route('milk.mandali-deliveries.update', $sale), [
        'quantity' => '45.000',
        'unit_rate' => '72.00',
        'slip' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $paths = MilkSale::query()->pluck('slip_path')->filter()->all();
    $logs = AuditLog::query()->get();

    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        $json = json_encode([$log->old_values, $log->new_values], JSON_UNESCAPED_SLASHES);

        expect($json)->not->toContain(MilkSaleSlips::DIRECTORY);

        foreach ($paths as $path) {
            expect($json)->not->toContain($path);
        }
    }

    // What is recorded is the display name and whether one is attached.
    $updated = AuditLog::query()->where('action', AuditAction::Updated->value)->latest('id')->firstOrFail();

    expect($updated->new_values)->toHaveKey('slip_name')
        ->and($updated->new_values['slip_name'])->toBe('replacement.pdf')
        ->and($updated->old_values['slip_name'])->toBe('slip.pdf');
});

test('a path is dropped even from an audit payload that passes a whole model', function () {
    /*
     * The callers pass explicit payloads, so this is the backstop: `slip_path` is a
     * noise key, and a future caller auditing a whole MilkSale cannot copy the private
     * path into a record that more people can read than can open the file.
     */
    $sale = ($this->record)(['slip' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf')]);

    app(AuditLogger::class)->updated($sale, ['quantity' => '1.000']);

    $log = AuditLog::query()->where('action', AuditAction::Updated->value)->latest('id')->firstOrFail();

    expect(json_encode([$log->old_values, $log->new_values]))->not->toContain(MilkSaleSlips::DIRECTORY)
        ->and($log->new_values)->not->toHaveKey('slip_path');
});
