<?php

use App\Enums\AdjustmentDirection;
use App\Enums\LedgerDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\BuyerSettlement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\Partner;
use App\Models\PartnerContribution;
use App\Models\SalesChannel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * These bypass Form Requests deliberately.
 *
 * Validation protects the user from a mistake; a database constraint protects
 * the books from a bug, a race or a console mistake. For the invariants that
 * decide whether money is right, both layers should hold, and only the second
 * one holds when the first is skipped.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedPhase2Masters();
});

test('a ledger idempotency key cannot be reused', function () {
    $account = FinancialAccount::factory()->for($this->business)->create();

    $attributes = [
        'financial_account_id' => $account->id,
        'entry_date' => '2026-09-01',
        'direction' => LedgerDirection::Debit->value,
        'amount' => '100.00',
        'description' => 'Feed',
        'idempotency_key' => 'expense_funding:1',
    ];

    FinancialLedgerEntry::create($attributes);

    expect(fn () => FinancialLedgerEntry::create($attributes))->toThrow(QueryException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(1);
});

test('an entry can be reversed only once, enforced by the database', function () {
    $account = FinancialAccount::factory()->for($this->business)->create();

    $original = FinancialLedgerEntry::create([
        'financial_account_id' => $account->id,
        'entry_date' => '2026-09-01',
        'direction' => LedgerDirection::Debit->value,
        'amount' => '100.00',
        'description' => 'Feed',
        'idempotency_key' => 'k1',
    ]);

    $reversalAttributes = fn (string $key): array => [
        'financial_account_id' => $account->id,
        'entry_date' => '2026-09-02',
        'direction' => LedgerDirection::Credit->value,
        'amount' => '100.00',
        'description' => 'Reversal',
        'idempotency_key' => $key,
        'reverses_entry_id' => $original->id,
    ];

    FinancialLedgerEntry::create($reversalAttributes('k2'));

    // Different idempotency key, same target: still refused.
    expect(fn () => FinancialLedgerEntry::create($reversalAttributes('k3')))
        ->toThrow(QueryException::class);

    expect(FinancialLedgerEntry::query()->count())->toBe(2);
});

test('system codes are unique', function (string $table, string $column, string $value) {
    expect(fn () => DB::table($table)->insert([
        'name' => 'Duplicate',
        $column => $value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'payment method code' => ['payment_methods', 'code', 'cash'],
    'expense category code' => ['expense_categories', 'code', 'medicine'],
]);

test('a sales channel slug is unique within a business but free across businesses', function () {
    $other = Business::factory()->create();

    expect(fn () => SalesChannel::create([
        'business_id' => $this->business->id,
        'slug' => SalesChannel::MANDALI,
        'name' => 'Duplicate Mandali',
    ]))->toThrow(QueryException::class);

    // Another business may use the same slug.
    $allowed = SalesChannel::create([
        'business_id' => $other->id,
        'slug' => SalesChannel::MANDALI,
        'name' => 'Their Mandali',
    ]);

    expect($allowed->exists)->toBeTrue();
});

test('two price rules cannot start on the same day for one business and milk type', function () {
    MilkPriceRule::factory()->for($this->business)->forType(MilkType::Cow)
        ->period('2026-09-01')->create();

    expect(fn () => MilkPriceRule::factory()->for($this->business)->forType(MilkType::Cow)
        ->period('2026-09-01')->create())->toThrow(QueryException::class);

    // The other milk type on the same day is fine.
    $buffalo = MilkPriceRule::factory()->for($this->business)->forType(MilkType::Buffalo)
        ->period('2026-09-01')->create();

    expect($buffalo->exists)->toBeTrue();
});

test('two buyer price rules cannot start on the same day for one buyer and milk type', function () {
    $channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $buyer = Buyer::factory()->inChannel($channel)->create();
    $other = Buyer::factory()->inChannel($channel)->create();

    BuyerPriceRule::factory()->for($buyer)->forType(MilkType::Cow)->period('2026-09-01')->create();

    expect(fn () => BuyerPriceRule::factory()->for($buyer)->forType(MilkType::Cow)
        ->period('2026-09-01')->create())->toThrow(QueryException::class);

    // A different buyer on the same day is fine.
    expect(BuyerPriceRule::factory()->for($other)->forType(MilkType::Cow)
        ->period('2026-09-01')->create()->exists)->toBeTrue();
});

test('a financial account name is unique within a business', function () {
    FinancialAccount::factory()->for($this->business)->create(['name' => 'Cash']);

    expect(fn () => FinancialAccount::factory()->for($this->business)->create(['name' => 'Cash']))
        ->toThrow(QueryException::class);
});

test('a ledger entry cannot point at an account that does not exist', function () {
    expect(fn () => FinancialLedgerEntry::create([
        'financial_account_id' => 999999,
        'entry_date' => '2026-09-01',
        'direction' => LedgerDirection::Debit->value,
        'amount' => '100.00',
        'description' => 'Orphan',
        'idempotency_key' => 'orphan',
    ]))->toThrow(QueryException::class);
});

test('an account referenced by the ledger cannot be deleted out from under it', function () {
    $account = FinancialAccount::factory()->for($this->business)->create();

    FinancialLedgerEntry::create([
        'financial_account_id' => $account->id,
        'entry_date' => '2026-09-01',
        'direction' => LedgerDirection::Debit->value,
        'amount' => '100.00',
        'description' => 'Feed',
        'idempotency_key' => 'k1',
    ]);

    // restrictOnDelete: history must not be orphaned.
    expect(fn () => DB::table('financial_accounts')->where('id', $account->id)->delete())
        ->toThrow(QueryException::class);
});

test('a category referenced by an expense cannot be deleted out from under it', function () {
    $category = ExpenseCategory::query()->firstOrFail();
    Expense::factory()->for($this->business)->create(['expense_category_id' => $category->id]);

    expect(fn () => DB::table('expense_categories')->where('id', $category->id)->delete())
        ->toThrow(QueryException::class);
});

test('a partner referenced by a contribution cannot be deleted out from under it', function () {
    $partner = Partner::factory()->for($this->business)->create();
    $account = FinancialAccount::factory()->for($this->business)->create();

    PartnerContribution::factory()->for($partner)
        ->create(['financial_account_id' => $account->id]);

    expect(fn () => DB::table('partners')->where('id', $partner->id)->delete())
        ->toThrow(QueryException::class);
});

test('every money column is decimal, never float or double', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('decimal');
})->with([
    ['financial_accounts', 'opening_balance'],
    ['financial_ledger_entries', 'amount'],
    ['funding_allocations', 'amount'],
    ['expenses', 'amount'],
    ['partner_contributions', 'amount'],
    ['milk_price_rules', 'rate'],
    ['buyer_price_rules', 'rate'],
]);

test('business dates are stored as dates, not timestamps', function (string $table, string $column) {
    // Reporting by business date must not depend on a timezone conversion.
    expect(Schema::getColumnType($table, $column))->toBe('date');
})->with([
    ['expenses', 'expense_date'],
    ['partner_contributions', 'contribution_date'],
    ['financial_ledger_entries', 'entry_date'],
    ['milk_price_rules', 'effective_from'],
    ['buyer_price_rules', 'effective_from'],
]);

test('polymorphic columns are strings wide enough for an alias but not a class name', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('varchar');
})->with([
    ['funding_allocations', 'payable_type'],
    ['funding_allocations', 'source_type'],
    ['financial_ledger_entries', 'reference_type'],
    ['audit_logs', 'auditable_type'],
]);

test('the audit table has no updated_at, because entries are never updated', function () {
    expect(Schema::hasColumn('audit_logs', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Phase 3 — milk
|--------------------------------------------------------------------------
|
| The milk tables get the same treatment as the financial ones: the guarantees
| that must survive a bug in the application layer live in the schema.
*/

test('milk quantities are decimal, never float or double', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('decimal');
})->with([
    ['milk_productions', 'cow_milk_quantity'],
    ['milk_productions', 'buffalo_milk_quantity'],
    ['milk_usages', 'quantity'],
    ['milk_adjustments', 'quantity'],
]);

test('milk quantities carry exactly three decimal places', function (string $table, string $column) {
    $type = DB::selectOne(
        'SELECT COLUMN_TYPE as column_type FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    expect(strtolower((string) $type->column_type))->toBe('decimal(10,3)');
})->with([
    ['milk_productions', 'cow_milk_quantity'],
    ['milk_productions', 'buffalo_milk_quantity'],
    ['milk_usages', 'quantity'],
    ['milk_adjustments', 'quantity'],
]);

test('milk business dates are stored as dates', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('date');
})->with([
    ['milk_productions', 'production_date'],
    ['milk_usages', 'usage_date'],
    ['milk_adjustments', 'adjustment_date'],
]);

test('milk enumerations are strings backed by PHP enums, not MySQL ENUMs', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('varchar');
})->with([
    ['milk_productions', 'shift'],
    ['milk_usages', 'shift'],
    ['milk_usages', 'milk_type'],
    ['milk_usages', 'usage_type'],
    ['milk_usages', 'status'],
    ['milk_adjustments', 'shift'],
    ['milk_adjustments', 'milk_type'],
    ['milk_adjustments', 'direction'],
    ['milk_adjustments', 'status'],
]);

test('production is unique on farm, date and shift and nothing else', function () {
    $unique = collect(Schema::getIndexes('milk_productions'))
        ->filter(fn (array $index): bool => (bool) $index['unique'])
        ->reject(fn (array $index): bool => (bool) $index['primary'])
        ->values();

    expect($unique)->toHaveCount(1)
        ->and($unique[0]['columns'])->toBe(['farm_id', 'production_date', 'shift']);
});

test('a duplicate production row is rejected by the database', function () {
    $business = seedBusiness();
    $farm = $business->primaryFarm();

    MilkProduction::factory()->for($farm)->morning()->on('2026-09-10')->create();

    expect(fn () => DB::table('milk_productions')->insert([
        'farm_id' => $farm->id,
        'production_date' => '2026-09-10',
        'shift' => Shift::Morning->value,
        'cow_milk_quantity' => '1.000',
        'buffalo_milk_quantity' => '1.000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('an adjustment reason cannot be null, because it justifies the row', function () {
    $column = DB::selectOne(
        'SELECT IS_NULLABLE as is_nullable FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['milk_adjustments', 'reason']
    );

    expect(strtoupper((string) $column->is_nullable))->toBe('NO');
});

test('an adjustment cannot be inserted without a reason', function () {
    $business = seedBusiness();

    expect(fn () => DB::table('milk_adjustments')->insert([
        'farm_id' => $business->primaryFarm()->id,
        'adjustment_date' => '2026-09-10',
        'shift' => Shift::Morning->value,
        'milk_type' => MilkType::Cow->value,
        'direction' => AdjustmentDirection::Increase->value,
        'quantity' => '1.000',
        // reason omitted
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a milk record cannot point at a farm that does not exist', function (string $table, array $row) {
    expect(fn () => DB::table($table)->insert($row + ['created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
})->with([
    'production' => ['milk_productions', [
        'farm_id' => 999999,
        'production_date' => '2026-09-10',
        'shift' => 'morning',
        'cow_milk_quantity' => '1.000',
        'buffalo_milk_quantity' => '1.000',
    ]],
    'usage' => ['milk_usages', [
        'farm_id' => 999999,
        'usage_date' => '2026-09-10',
        'shift' => 'morning',
        'milk_type' => 'cow',
        'usage_type' => 'calf_feeding',
        'quantity' => '1.000',
        'status' => 'active',
    ]],
    'adjustment' => ['milk_adjustments', [
        'farm_id' => 999999,
        'adjustment_date' => '2026-09-10',
        'shift' => 'morning',
        'milk_type' => 'cow',
        'direction' => 'increase',
        'quantity' => '1.000',
        'reason' => 'Testing the foreign key',
        'status' => 'active',
    ]],
]);

test('a farm with milk records cannot be deleted out from under them', function () {
    $business = seedBusiness();
    $farm = $business->primaryFarm();

    MilkProduction::factory()->for($farm)->morning()->on('2026-09-10')->create();

    expect(fn () => DB::table('farms')->where('id', $farm->id)->delete())
        ->toThrow(QueryException::class);
});

test('deleting the user who recorded milk keeps the record and nulls the reference', function () {
    $business = seedBusiness();
    seedAuthorization();
    $user = superAdmin();

    $production = MilkProduction::factory()->for($business->primaryFarm())
        ->morning()->on('2026-09-10')->create([
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

    DB::table('users')->where('id', $user->id)->delete();

    // History survives the person; the attribution simply goes unknown.
    expect($production->fresh())->not->toBeNull()
        ->and($production->fresh()->created_by)->toBeNull()
        ->and($production->fresh()->updated_by)->toBeNull();
});

test('cancellation columns exist on the records that can be cancelled', function (string $table) {
    expect(Schema::hasColumn($table, 'status'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'cancelled_at'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'cancelled_by'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'cancellation_reason'))->toBeTrue();
})->with(['milk_usages', 'milk_adjustments']);

test('production has no cancellation columns, because it is corrected in place', function () {
    // A cancelled production row would leave a shift with no production while a
    // row exists, which muddies the missing-versus-zero distinction (D33).
    expect(Schema::hasColumn('milk_productions', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('milk_productions', 'cancelled_at'))->toBeFalse();
});

test('production records who created and who last updated a shift', function () {
    expect(Schema::hasColumn('milk_productions', 'created_by'))->toBeTrue()
        ->and(Schema::hasColumn('milk_productions', 'updated_by'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Phase 5 guarantees that live in the schema
|--------------------------------------------------------------------------
*/

test('a receivable adjustment cannot be inserted without a reason', function () {
    $buyer = Buyer::factory()->mandali($this->business)->create();

    // An unexplained change to what somebody owes is the thing this table exists to
    // prevent, so the rule is `NOT NULL` rather than only a Form Request.
    expect(fn () => DB::table('buyer_balance_adjustments')->insert([
        'buyer_id' => $buyer->id,
        'adjustment_date' => '2026-09-30',
        'direction' => 'increase',
        'amount' => '100.00',
        // reason omitted
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the database allows only one active adjustment per settlement', function () {
    $buyer = Buyer::factory()->mandali($this->business)->create();
    $settlement = BuyerSettlement::factory()->for($buyer)->create();

    $row = [
        'buyer_id' => $buyer->id,
        'buyer_settlement_id' => $settlement->id,
        'adjustment_date' => '2026-09-30',
        'direction' => 'increase',
        'amount' => '100.00',
        'reason' => 'Settlement difference',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('buyer_balance_adjustments')->insert($row);

    /*
     * MySQL has no partial unique indexes, so this is enforced by a generated column
     * — the settlement id while the row is active, NULL once it is cancelled — with a
     * unique index on it. The guarantee is that a finalized settlement's difference
     * exists exactly once, whatever the application layer does.
     */
    expect(fn () => DB::table('buyer_balance_adjustments')->insert($row))
        ->toThrow(QueryException::class);

    // Cancelled, the first row leaves the index and a replacement is accepted.
    DB::table('buyer_balance_adjustments')
        ->where('buyer_settlement_id', $settlement->id)
        ->update(['status' => 'cancelled']);

    DB::table('buyer_balance_adjustments')->insert($row);

    expect(DB::table('buyer_balance_adjustments')->count())->toBe(2);
});

test('an unlinked adjustment is not constrained by the settlement index', function () {
    $buyer = Buyer::factory()->mandali($this->business)->create();

    $row = [
        'buyer_id' => $buyer->id,
        'buyer_settlement_id' => null,
        'adjustment_date' => '2026-09-30',
        'direction' => 'decrease',
        'amount' => '50.00',
        'reason' => 'Agreed correction',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Two adjustments attached to no settlement are two legitimate corrections, and
    // the generated column is NULL for both — which a unique index ignores.
    DB::table('buyer_balance_adjustments')->insert($row);
    DB::table('buyer_balance_adjustments')->insert($row);

    expect(DB::table('buyer_balance_adjustments')->count())->toBe(2);
});

test('a settlement and an adjustment cannot point at a buyer that does not exist', function (string $table, array $row) {
    expect(fn () => DB::table($table)->insert($row + ['created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
})->with([
    'settlement' => ['buyer_settlements', [
        'buyer_id' => 999999,
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'status' => 'draft',
    ]],
    'adjustment' => ['buyer_balance_adjustments', [
        'buyer_id' => 999999,
        'adjustment_date' => '2026-09-30',
        'direction' => 'increase',
        'amount' => '100.00',
        'reason' => 'Orphan',
        'status' => 'active',
    ]],
]);

test('a buyer with a settlement cannot be deleted', function () {
    $buyer = Buyer::factory()->mandali($this->business)->create();
    BuyerSettlement::factory()->for($buyer)->create();

    // RESTRICT, so a settled period cannot be orphaned by removing the buyer it
    // belongs to. Buyers are archived rather than deleted anyway; this is the backstop.
    expect(fn () => DB::table('buyers')->where('id', $buyer->id)->delete())
        ->toThrow(QueryException::class);
});

test('the Phase 5 money and milk columns have the decimal types the specification requires', function () {
    expect(Schema::getColumnType('buyer_settlements', 'milk_quantity'))->toBe('decimal')
        ->and(Schema::getColumnType('buyer_settlements', 'expected_amount'))->toBe('decimal')
        ->and(Schema::getColumnType('buyer_settlements', 'statement_amount'))->toBe('decimal')
        ->and(Schema::getColumnType('buyer_settlements', 'difference'))->toBe('decimal')
        ->and(Schema::getColumnType('buyer_balance_adjustments', 'amount'))->toBe('decimal')
        ->and(Schema::getColumnType('milk_sales', 'resolved_rate'))->toBe('decimal')
        // Business dates, never timestamps (D7).
        ->and(Schema::getColumnType('buyer_settlements', 'period_start'))->toBe('date')
        ->and(Schema::getColumnType('buyer_settlements', 'period_end'))->toBe('date')
        ->and(Schema::getColumnType('buyer_balance_adjustments', 'adjustment_date'))->toBe('date');
});

test('cancellation columns exist on the Phase 5 records that can be withdrawn', function (string $table) {
    expect(Schema::hasColumn($table, 'status'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'cancelled_at'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'cancelled_by'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'cancellation_reason'))->toBeTrue();
})->with(['buyer_settlements', 'buyer_balance_adjustments', 'milk_sales', 'buyer_payments']);
