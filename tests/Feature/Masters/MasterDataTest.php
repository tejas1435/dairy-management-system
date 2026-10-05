<?php

use App\Actions\Expenses\CreateExpense;
use App\Models\Buyer;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\MilkPriceRule;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\MilkPriceSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SalesChannelSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->admin = userWithPermissions(['settings.manage']);
});

/*
|--------------------------------------------------------------------------
| Payment methods
|--------------------------------------------------------------------------
*/

test('the five system payment methods are seeded with stable codes', function () {
    expect(PaymentMethod::query()->pluck('code')->sort()->values()->all())
        ->toBe(['bank_transfer', 'cash', 'cheque', 'other', 'upi']);

    expect(PaymentMethod::query()->where('is_system', true)->count())->toBe(5);
});

test('renaming a payment method leaves its code, and therefore business logic, alone', function () {
    $upi = PaymentMethod::query()->where('code', PaymentMethod::UPI)->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('settings.payment-methods.update', $upi), ['name' => 'UPI / QR'])
        ->assertRedirect();

    $upi->refresh();

    expect($upi->name)->toBe('UPI / QR')
        ->and($upi->code)->toBe('upi');
});

test('a payment method can be deactivated and reactivated', function () {
    $cheque = PaymentMethod::query()->where('code', PaymentMethod::CHEQUE)->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('settings.payment-methods.status.update', $cheque), ['is_active' => 0])->assertRedirect();
    expect($cheque->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)
        ->put(route('settings.payment-methods.status.update', $cheque), ['is_active' => 1])->assertRedirect();
    expect($cheque->fresh()->is_active)->toBeTrue();
});

test('a seeded payment method cannot be deleted', function () {
    $cash = PaymentMethod::query()->where('code', PaymentMethod::CASH)->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('settings.payment-methods.destroy', $cash))
        ->assertSessionHasErrors('method');

    expect(PaymentMethod::query()->whereKey($cash->id)->exists())->toBeTrue();
});

test('a custom payment method that has been used cannot be deleted', function () {
    $custom = PaymentMethod::factory()->create(['name' => 'Voucher', 'code' => 'voucher']);

    $accounts = seedAccounts($this->business, cashOpening: '10000.00');

    app(CreateExpense::class)->handle([
        'expense_category_id' => ExpenseCategory::query()->firstOrFail()->id,
        'expense_date' => '2026-09-01',
        'amount' => '100.00',
        'description' => 'Used the voucher method',
    ], [[
        'source_type' => 'financial_account',
        'source_id' => $accounts['cash']->id,
        'amount' => '100.00',
        'payment_method_id' => $custom->id,
    ]]);

    $this->actingAs($this->admin)
        ->delete(route('settings.payment-methods.destroy', $custom))
        ->assertSessionHasErrors('method');

    expect(PaymentMethod::query()->whereKey($custom->id)->exists())->toBeTrue();
});

test('an unused custom payment method can be removed', function () {
    $custom = PaymentMethod::factory()->create(['code' => 'unused_method']);

    $this->actingAs($this->admin)
        ->delete(route('settings.payment-methods.destroy', $custom))->assertRedirect();

    expect(PaymentMethod::query()->whereKey($custom->id)->exists())->toBeFalse();
});

test('nothing in the payment layer processes a payment', function () {
    // MASTER_SPEC sections 26 and 46: methods are a record of how money moved.
    $source = file_get_contents(app_path('Http/Controllers/Settings/PaymentMethodController.php'))
        .file_get_contents(app_path('Models/PaymentMethod.php'));

    foreach (['razorpay', 'stripe', 'gateway', 'payu', 'paytm', 'Http::post'] as $forbidden) {
        expect(strtolower($source))->not->toContain(strtolower($forbidden));
    }
});

/*
|--------------------------------------------------------------------------
| Expense categories
|--------------------------------------------------------------------------
*/

test('the ten seeded categories exist, including the one Phase 6 looks up by code', function () {
    expect(ExpenseCategory::query()->count())->toBe(10)
        ->and(ExpenseCategory::query()->where('code', ExpenseCategory::ANIMAL_PURCHASE)->exists())
        ->toBeTrue();
});

test('a custom category can be created, renamed and deactivated', function () {
    $this->actingAs($this->admin)->post(route('settings.expense-categories.store'), [
        'name' => 'Transport', 'code' => 'transport',
    ])->assertRedirect();

    $category = ExpenseCategory::query()->where('code', 'transport')->firstOrFail();
    expect($category->is_system)->toBeFalse();

    $this->actingAs($this->admin)
        ->put(route('settings.expense-categories.update', $category), ['name' => 'Transport & Fuel'])
        ->assertRedirect();
    expect($category->fresh()->name)->toBe('Transport & Fuel');

    $this->actingAs($this->admin)
        ->put(route('settings.expense-categories.status.update', $category), ['is_active' => 0])
        ->assertRedirect();
    expect($category->fresh()->is_active)->toBeFalse();
});

test('a category used by an expense cannot be deleted', function () {
    $category = ExpenseCategory::factory()->create(['code' => 'temporary']);
    $accounts = seedAccounts($this->business, cashOpening: '10000.00');

    app(CreateExpense::class)->handle([
        'expense_category_id' => $category->id,
        'expense_date' => '2026-09-01',
        'amount' => '100.00',
        'description' => 'Something',
    ], [[
        'source_type' => 'financial_account',
        'source_id' => $accounts['cash']->id,
        'amount' => '100.00',
    ]]);

    $this->actingAs($this->admin)
        ->delete(route('settings.expense-categories.destroy', $category))
        ->assertSessionHasErrors('category');

    expect(ExpenseCategory::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('a seeded category cannot be deleted', function () {
    $seeded = ExpenseCategory::query()->where('code', 'medicine')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('settings.expense-categories.destroy', $seeded))
        ->assertSessionHasErrors('category');
});

/*
|--------------------------------------------------------------------------
| Sales channels
|--------------------------------------------------------------------------
*/

test('the three system channels are seeded with the slugs later phases branch on', function () {
    $channels = SalesChannel::query()->where('is_system', true)->get();

    expect($channels->pluck('slug')->sort()->values()->all())
        ->toBe(['direct_customer', 'mandali', 'vendor']);

    foreach ($channels as $channel) {
        expect($channel->isSystemChannel())->toBeTrue();
    }
});

test('a system channel can be renamed but keeps its slug', function () {
    $mandali = SalesChannel::query()->where('slug', SalesChannel::MANDALI)->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('settings.sales-channels.update', $mandali), ['name' => 'Dudh Mandali'])
        ->assertRedirect();

    $mandali->refresh();

    expect($mandali->name)->toBe('Dudh Mandali')
        ->and($mandali->slug)->toBe('mandali');
});

test('a system channel cannot be deleted', function () {
    $vendor = SalesChannel::query()->where('slug', SalesChannel::VENDOR)->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('settings.sales-channels.destroy', $vendor))
        ->assertSessionHasErrors('channel');

    expect(SalesChannel::query()->whereKey($vendor->id)->exists())->toBeTrue();
});

test('a custom channel can be created and used', function () {
    $this->actingAs($this->admin)->post(route('settings.sales-channels.store'), [
        'name' => 'Sweet Shop', 'slug' => 'sweet_shop',
    ])->assertRedirect();

    $channel = SalesChannel::query()->where('slug', 'sweet_shop')->firstOrFail();

    expect($channel->is_system)->toBeFalse()
        ->and($channel->isSystemChannel())->toBeFalse()
        ->and($channel->business_id)->toBe($this->business->id);
});

test('a custom channel cannot claim a reserved system slug', function (string $slug) {
    $this->actingAs($this->admin)->post(route('settings.sales-channels.store'), [
        'name' => 'Impostor', 'slug' => $slug,
    ])->assertSessionHasErrors('slug');
})->with(['mandali', 'vendor', 'direct_customer', 'Mandali', 'VENDOR', 'Direct-Customer']);

test('a custom channel slug is stored lower case with underscores', function () {
    $this->actingAs($this->admin)->post(route('settings.sales-channels.store'), [
        'name' => 'Sweet Shop', 'slug' => 'Sweet-Shop',
    ])->assertRedirect();

    expect(SalesChannel::query()->latest('id')->value('slug'))->toBe('sweet_shop');
});

test('a channel with buyers cannot be deleted', function () {
    $channel = SalesChannel::factory()->for($this->business)->create(['slug' => 'hotel']);
    Buyer::factory()->inChannel($channel)->create();

    $this->actingAs($this->admin)
        ->delete(route('settings.sales-channels.destroy', $channel))
        ->assertSessionHasErrors('channel');

    expect(SalesChannel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

test('an empty custom channel can be deleted', function () {
    $channel = SalesChannel::factory()->for($this->business)->create(['slug' => 'tea_shop']);

    $this->actingAs($this->admin)
        ->delete(route('settings.sales-channels.destroy', $channel))->assertRedirect();

    expect(SalesChannel::query()->whereKey($channel->id)->exists())->toBeFalse();
});

test('no Mandali or vendor workflow has leaked into the shared channel masters', function () {
    /*
     * This guard has now outlived two boundaries. Phase 4 built `milk_sales` and
     * `buyer_payments`; Phase 5 built `buyer_settlements` and
     * `buyer_balance_adjustments`. So the tables are no longer the test — what is,
     * and always was the point, is that a **sales channel stays data**. Every
     * channel-specific rule lives in code that branches on the slug, and nothing
     * about a channel is special-cased in the master's own schema.
     */
    expect(Schema::hasTable('buyer_settlements'))->toBeTrue()
        ->and(Schema::hasTable('buyer_balance_adjustments'))->toBeTrue();

    // A settlement belongs to a buyer, not to a channel: nothing ties the Mandali
    // workflow to the channel row.
    expect(Schema::getColumnListing('buyer_settlements'))
        ->toContain('buyer_id')
        ->not->toContain('sales_channel_id');

    // A channel row carries no workflow flags: the slug is what later phases branch
    // on, and nothing else about a channel is special-cased in the schema.
    expect(Schema::getColumnListing('sales_channels'))
        ->not->toContain('requires_fat')
        ->not->toContain('settlement_cycle')
        ->not->toContain('workflow');
});

/*
|--------------------------------------------------------------------------
| Settings authorisation
|--------------------------------------------------------------------------
*/

test('every settings master screen is refused without settings.manage', function (string $routeName) {
    $user = userWithPermissions(['dashboard.view']);

    $this->actingAs($user)->get(route($routeName))->assertForbidden();
})->with([
    'settings.payment-methods.index',
    'settings.expense-categories.index',
    'settings.sales-channels.index',
    'settings.milk-prices.index',
]);

test('settings writes are refused without the permission, not merely hidden', function () {
    $user = userWithPermissions(['dashboard.view']);

    $this->actingAs($user)->post(route('settings.sales-channels.store'), [
        'name' => 'Sneaky', 'slug' => 'sneaky',
    ])->assertForbidden();

    $this->actingAs($user)->post(route('settings.expense-categories.store'), [
        'name' => 'Sneaky', 'code' => 'sneaky',
    ])->assertForbidden();

    expect(SalesChannel::query()->where('slug', 'sneaky')->exists())->toBeFalse()
        ->and(ExpenseCategory::query()->where('code', 'sneaky')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Seeder idempotency
|--------------------------------------------------------------------------
*/

test('running every Phase 2 seeder twice creates no duplicates', function () {
    $this->seed(FinanceSeeder::class);
    $this->seed(MilkPriceSeeder::class);

    $before = [
        'methods' => PaymentMethod::query()->count(),
        'categories' => ExpenseCategory::query()->count(),
        'channels' => SalesChannel::query()->count(),
        'accounts' => FinancialAccount::query()->count(),
        'partners' => Partner::query()->count(),
        'prices' => MilkPriceRule::query()->count(),
    ];

    $this->seed(PaymentMethodSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);
    $this->seed(SalesChannelSeeder::class);
    $this->seed(FinanceSeeder::class);
    $this->seed(MilkPriceSeeder::class);

    expect(PaymentMethod::query()->count())->toBe($before['methods'])
        ->and(ExpenseCategory::query()->count())->toBe($before['categories'])
        ->and(SalesChannel::query()->count())->toBe($before['channels'])
        ->and(FinancialAccount::query()->count())->toBe($before['accounts'])
        ->and(Partner::query()->count())->toBe($before['partners'])
        ->and(MilkPriceRule::query()->count())->toBe($before['prices']);
});

test('re-seeding does not reopen a price period an administrator has since closed', function () {
    $this->seed(MilkPriceSeeder::class);

    $rule = MilkPriceRule::query()->firstOrFail();
    $rule->forceFill(['effective_to' => '2026-12-31'])->save();

    $this->seed(MilkPriceSeeder::class);

    expect($rule->fresh()->effective_to->toDateString())->toBe('2026-12-31')
        ->and(MilkPriceRule::query()->count())->toBe(2);
});

test('re-seeding keeps Super Admin holding every permission', function () {
    Permission::findOrCreate('a.brand.new.permission', 'web');

    seedAuthorization();

    $role = Role::query()->where('name', RoleCatalog::SUPER_ADMIN)->firstOrFail();

    // Only catalogue permissions are synced, so the hand-added one is not
    // granted -- which is the documented behaviour, not an oversight.
    expect($role->permissions)->toHaveCount(count(PermissionCatalog::all()));

    foreach (PermissionCatalog::all() as $permission) {
        expect($role->hasPermissionTo($permission))->toBeTrue("Super Admin lost {$permission}");
    }
});
