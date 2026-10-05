<?php

use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\LedgerDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\BuyerPayment;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PaymentMethod;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Customers\CustomerLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * Customer payments, the derived outstanding balance, and the customer ledger.
 *
 * The two rules that carry it: outstanding is never stored, and a payment larger than
 * the outstanding is refused rather than quietly creating a credit balance.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '1000.00', bankOpening: '5000.00');
    $this->cash = $accounts['cash'];
    $this->bank = $accounts['bank'];

    $this->admin = superAdmin();
    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00');
    seedProductionFor($this->farm, $this->date);

    $this->customer = directCustomer($this->business, [MilkType::Cow]);

    $this->outstanding = app(BuyerOutstandingService::class);
    $this->record = app(RecordBuyerPayment::class);
    $this->sell = app(SaveCustomerDailySale::class);

    /** Delivers milk worth a known amount, so there is something to pay. */
    $this->deliver = fn (string $quantity, ?string $date = null) => $this->sell->handle(
        $this->customer, $date ?? $this->date, Shift::Morning, MilkType::Cow, $quantity
    );

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. Schema
|--------------------------------------------------------------------------
*/

test('buyer_payments is generic and carries no outstanding column', function () {
    expect(Schema::hasTable('buyer_payments'))->toBeTrue()
        ->and(Schema::getColumnType('buyer_payments', 'payment_date'))->toBe('date')
        ->and(Schema::getColumnType('buyer_payments', 'amount'))->toBe('decimal');

    // Outstanding is derived, here and on the buyer.
    expect(Schema::hasColumn('buyer_payments', 'outstanding'))->toBeFalse()
        ->and(Schema::hasColumn('buyers', 'outstanding'))->toBeFalse()
        ->and(Schema::hasColumn('buyers', 'balance'))->toBeFalse();
});

test('the payment amount column carries money precision', function () {
    $type = DB::selectOne(
        'SELECT COLUMN_TYPE as column_type FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['buyer_payments', 'amount']
    );

    expect(strtolower((string) $type->column_type))->toBe('decimal(14,2)');
});

test('the settlement column arrived with the table it points at', function () {
    /*
     * Phase 4 asserted the opposite: no column, because `buyer_settlements` did not
     * exist and a foreign key pointing at nothing is the defect D13 exists to
     * prevent. Phase 5 created the table and added the column with it, so the guard
     * now checks the thing it was protecting — the column and its target arrived
     * together, and it is nullable, because a customer pays a running balance with
     * no settlement involved.
     */
    expect(Schema::hasTable('buyer_settlements'))->toBeTrue()
        ->and(Schema::hasColumn('buyer_payments', 'buyer_settlement_id'))->toBeTrue();

    $column = DB::selectOne(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['buyer_payments', 'buyer_settlement_id']
    );

    expect($column->IS_NULLABLE)->toBe('YES');
});

/*
|--------------------------------------------------------------------------
| B. Outstanding is derived
|--------------------------------------------------------------------------
*/

test('outstanding is sales minus payments, with adjustments as a named zero', function () {
    ($this->deliver)('10.000'); // 10 x 70.00 = 700.00

    $breakdown = $this->outstanding->breakdownFor($this->customer);

    expect($breakdown['sales'])->toBe('700.00')
        // The third term of the specification's formula, genuinely zero until Phase 5
        // rather than omitted.
        ->and($breakdown['adjustments'])->toBe('0.00')
        ->and($breakdown['payments'])->toBe('0.00')
        ->and($breakdown['outstanding'])->toBe('700.00');
});

test('a payment reduces the outstanding without anything being stored', function () {
    ($this->deliver)('10.000');

    $this->record->handle($this->customer, [
        'payment_date' => $this->date,
        'amount' => '300.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expect($this->outstanding->outstandingFor($this->customer))->toBe('400.00');
});

test('a cancelled sale leaves the outstanding balance', function () {
    ($this->deliver)('10.000');
    expect($this->outstanding->outstandingFor($this->customer))->toBe('700.00');

    // Clearing the grid quantity cancels the sale.
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, null);

    expect($this->outstanding->outstandingFor($this->customer))->toBe('0.00');
});

test('outstanding for many buyers agrees with the per-buyer figure', function () {
    $other = directCustomer($this->business, [MilkType::Cow]);

    ($this->deliver)('10.000');
    $this->sell->handle($other, $this->date, Shift::Morning, MilkType::Cow, '5.000');

    $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '200.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $many = $this->outstanding->outstandingForMany([$this->customer->id, $other->id]);

    expect($many[$this->customer->id])->toBe('500.00')
        ->and($many[$other->id])->toBe('350.00')
        ->and($many[$this->customer->id])->toBe($this->outstanding->outstandingFor($this->customer))
        ->and($many[$other->id])->toBe($this->outstanding->outstandingFor($other));
});

test('the bulk outstanding query does not grow with the number of buyers', function () {
    $customers = collect(range(1, 5))->map(fn () => directCustomer($this->business, [MilkType::Cow]));

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->outstanding->outstandingForMany($customers->pluck('id')->all());

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
     * Three grouped queries: sales, payments and — since Phase 5 filled in the third
     * term of the formula — receivable adjustments. Still a fixed number whatever the
     * buyer count, which is the property this guards.
     */
    expect($count)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| C. Recording a payment credits the account
|--------------------------------------------------------------------------
*/

test('recording a payment credits the receiving account through the ledger', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date,
        'amount' => '700.00',
        'financial_account_id' => $this->bank->id,
        'payment_method_id' => PaymentMethod::query()->where('code', 'upi')->value('id'),
        'reference' => 'UPI/12345',
    ]);

    expect($payment->amount)->toBe('700.00')
        ->and($payment->status)->toBe(TransactionStatus::Active)
        ->and($payment->created_by)->toBe($this->admin->id)
        ->and($payment->reference)->toBe('UPI/12345');

    $entry = FinancialLedgerEntry::query()
        ->where('reference_type', 'buyer_payment')
        ->where('reference_id', $payment->id)
        ->firstOrFail();

    expect($entry->direction)->toBe(LedgerDirection::Credit)
        ->and($entry->amount)->toBe('700.00')
        ->and($entry->financial_account_id)->toBe($this->bank->id);

    // Opening 5000 plus the 700 received.
    expectMoney($this->bank->fresh()->balance(), '5700.00');
});

test('the ledger entry carries a deterministic idempotency key', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $entry = FinancialLedgerEntry::query()->where('reference_id', $payment->id)->firstOrFail();

    expect($entry->idempotency_key)->toBe('buyer_payment:'.$payment->id);
});

test('two genuine payments are two records and two credits', function () {
    ($this->deliver)('10.000');

    $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '300.00',
        'financial_account_id' => $this->cash->id,
    ]);
    $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '200.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expect(BuyerPayment::query()->count())->toBe(2)
        ->and(FinancialLedgerEntry::query()->where('reference_type', 'buyer_payment')->count())->toBe(2)
        ->and($this->outstanding->outstandingFor($this->customer))->toBe('200.00');

    expectMoney($this->cash->fresh()->balance(), '1500.00');
});

test('a payment into an account of another business is refused', function () {
    ($this->deliver)('10.000');

    $foreign = FinancialAccount::factory()->create();

    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $foreign->id,
    ]))->toThrow(ValidationException::class);

    expect(BuyerPayment::query()->count())->toBe(0);
});

test('a payment into an inactive account is refused', function () {
    ($this->deliver)('10.000');

    $this->cash->forceFill(['is_active' => false])->save();

    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(ValidationException::class);

    expect(BuyerPayment::query()->count())->toBe(0);
});

test('a payment method that does not exist is refused', function () {
    ($this->deliver)('10.000');

    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => 999999,
    ]))->toThrow(ValidationException::class);
});

test('a payment method is optional', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expect($payment->payment_method_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| D. Overpayment is refused
|--------------------------------------------------------------------------
*/

test('a payment one paisa over the outstanding is refused', function () {
    ($this->deliver)('10.000'); // 700.00 owed

    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.01',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(ValidationException::class);

    expect(BuyerPayment::query()->count())->toBe(0)
        // And no credit slipped into the account.
        ->and(FinancialLedgerEntry::query()->where('reference_type', 'buyer_payment')->count())->toBe(0);

    expectMoney($this->cash->fresh()->balance(), '1000.00');
});

test('a payment of exactly the outstanding is allowed', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expect($payment->exists)->toBeTrue()
        ->and($this->outstanding->outstandingFor($this->customer))->toBe('0.00');
});

test('a payment when nothing is outstanding is refused', function () {
    // No deliveries at all.
    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(ValidationException::class);

    expect(BuyerPayment::query()->count())->toBe(0);
});

test('a second payment cannot take the balance below zero', function () {
    ($this->deliver)('10.000');

    $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    // Fully settled, so nothing more can be received.
    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '0.01',
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(ValidationException::class);

    expect($this->outstanding->outstandingFor($this->customer))->toBe('0.00');
});

test('the refusal names both the amount and the outstanding figure', function () {
    ($this->deliver)('10.000');

    try {
        $this->record->handle($this->customer, [
            'payment_date' => $this->date, 'amount' => '1000.00',
            'financial_account_id' => $this->cash->id,
        ]);
        $this->fail('Expected the overpayment to be refused.');
    } catch (ValidationException $exception) {
        $message = $exception->errors()['amount'][0];

        expect($message)->toContain('1,000.00')
            ->and($message)->toContain('700.00');
    }
});

test('a zero or negative payment is refused', function (string $amount) {
    ($this->deliver)('10.000');

    expect(fn () => $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => $amount,
        'financial_account_id' => $this->cash->id,
    ]))->toThrow(ValidationException::class);
})->with(['0', '0.00', '-100.00']);

test('the form refuses an overpayment too, and writes nothing', function () {
    ($this->deliver)('10.000');

    $this->post(route('customers.payments.store', $this->customer), [
        'payment_date' => $this->date,
        'amount' => '900.00',
        'financial_account_id' => $this->cash->id,
    ])->assertSessionHasErrors('amount');

    expect(BuyerPayment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| E. Cancellation reverses exactly once
|--------------------------------------------------------------------------
*/

test('cancelling a payment keeps it and posts one reversing debit', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expectMoney($this->cash->fresh()->balance(), '1700.00');

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque bounced');

    $payment->refresh();

    expect(BuyerPayment::query()->count())->toBe(1)
        ->and($payment->status)->toBe(TransactionStatus::Cancelled)
        ->and($payment->cancellation_reason)->toBe('Cheque bounced')
        ->and($payment->cancelled_by)->toBe($this->admin->id)
        // The amount is untouched; only its effect stops.
        ->and($payment->amount)->toBe('700.00');

    $entries = FinancialLedgerEntry::query()
        ->where('reference_type', 'buyer_payment')
        ->where('reference_id', $payment->id)
        ->get();

    // The original credit and exactly one reversal, both retained.
    expect($entries)->toHaveCount(2)
        ->and($entries->where('direction', LedgerDirection::Credit)->count())->toBe(1)
        ->and($entries->where('direction', LedgerDirection::Debit)->count())->toBe(1);

    expectMoney($this->cash->fresh()->balance(), '1000.00');
});

test('cancelling a payment restores the outstanding balance', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    expect($this->outstanding->outstandingFor($this->customer))->toBe('0.00');

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque bounced');

    expect($this->outstanding->outstandingFor($this->customer))->toBe('700.00');
});

test('cancelling twice is refused and posts no second reversal', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    app(CancelBuyerPayment::class)->handle($payment, 'First withdrawal');

    expect(fn () => app(CancelBuyerPayment::class)->handle($payment->fresh(), 'Second attempt'))
        ->toThrow(ValidationException::class);

    expect(FinancialLedgerEntry::query()
        ->where('reference_type', 'buyer_payment')
        ->where('direction', LedgerDirection::Debit->value)
        ->count())->toBe(1);

    expectMoney($this->cash->fresh()->balance(), '1000.00');
});

test('cancelling requires a usable reason', function (string $reason) {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $this->put(route('customers.payments.cancel', $payment), ['cancellation_reason' => $reason])
        ->assertSessionHasErrors('cancellation_reason');

    expect($payment->fresh()->isCancelled())->toBeFalse();
})->with(['empty' => [''], 'too short' => ['no']]);

test('after cancelling, the freed amount can be received again', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
    ]);

    app(CancelBuyerPayment::class)->handle($payment, 'Recorded against the wrong customer');

    // The outstanding is back, so a correct payment now fits.
    $replacement = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->bank->id,
    ]);

    expect($replacement->exists)->toBeTrue()
        ->and($this->outstanding->outstandingFor($this->customer))->toBe('0.00');

    expectMoney($this->cash->fresh()->balance(), '1000.00');
    expectMoney($this->bank->fresh()->balance(), '5700.00');
});

test('there is no route for deleting a payment', function () {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true))
        ->map(fn ($route): ?string => $route->getName())
        ->filter();

    expect($names)->not->toContain('customers.payments.destroy');
});

/*
|--------------------------------------------------------------------------
| F. Audit
|--------------------------------------------------------------------------
*/

test('recording and cancelling a payment are both audited', function () {
    ($this->deliver)('10.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '700.00',
        'financial_account_id' => $this->cash->id,
        'reference' => 'Cheque 4411',
    ]);

    app(CancelBuyerPayment::class)->handle($payment, 'Cheque bounced');

    $logs = AuditLog::query()->where('auditable_type', 'buyer_payment')->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action->value)->toBe('created')
        ->and($logs[0]->new_values['amount'])->toBe('700.00')
        ->and($logs[0]->new_values['reference'])->toBe('Cheque 4411')
        ->and($logs[1]->action->value)->toBe('cancelled')
        ->and($logs[1]->new_values['cancellation_reason'])->toBe('Cheque bounced');
});

/*
|--------------------------------------------------------------------------
| G. The customer ledger
|--------------------------------------------------------------------------
*/

test('there is no customer ledger table: the ledger is derived', function () {
    expect(Schema::hasTable('customer_ledger'))->toBeFalse()
        ->and(Schema::hasTable('customer_ledger_entries'))->toBeFalse();
});

test('the ledger pivots shifts into morning and evening columns', function () {
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '1.500');
    $this->sell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '2.500');

    $statement = app(CustomerLedgerService::class)->statement($this->customer, $this->date, $this->date);

    expect($statement['rows'])->toHaveCount(1);

    $row = $statement['rows']->first();

    expect($row->morningQuantity)->toBe('1.500')
        ->and($row->eveningQuantity)->toBe('2.500')
        ->and($row->totalQuantity)->toBe('4.000')
        ->and($row->rate)->toBe('70.00')
        // 4.000 x 70.00
        ->and($row->amount)->toBe('280.00');
});

test('a customer taking both milk types gets a row per type, not a corrupt total', function () {
    $both = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);
    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');

    $this->sell->handle($both, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->sell->handle($both, $this->date, Shift::Morning, MilkType::Buffalo, '1.000');

    $statement = app(CustomerLedgerService::class)->statement($both, $this->date, $this->date);

    // Two rows, each with its own rate. One row would have to pick a single rate for
    // 3 litres of two different milks, which is meaningless.
    expect($statement['rows'])->toHaveCount(2);

    $byType = $statement['rows']->keyBy(fn ($row) => $row->milkType->value);

    expect($byType['cow']->rate)->toBe('70.00')
        ->and($byType['cow']->amount)->toBe('140.00')
        ->and($byType['buffalo']->rate)->toBe('85.00')
        ->and($byType['buffalo']->amount)->toBe('85.00');
});

test('the running balance accumulates sales and reduces on payments', function () {
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000'); // 140.00

    seedProductionFor($this->farm, '2026-10-11');
    $this->sell->handle($this->customer, '2026-10-11', Shift::Morning, MilkType::Cow, '1.000'); // 70.00

    $this->record->handle($this->customer, [
        'payment_date' => '2026-10-11', 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $statement = app(CustomerLedgerService::class)->statement($this->customer, '2026-10-10', '2026-10-11');

    $balances = $statement['rows']->pluck('balance')->all();

    // 140.00, then 210.00, then 110.00 after the payment.
    expect($balances)->toBe(['140.00', '210.00', '110.00'])
        ->and($statement['closing'])->toBe('110.00');
});

test('the opening balance carries everything before the window', function () {
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000'); // 140.00

    seedProductionFor($this->farm, '2026-11-01');
    $this->sell->handle($this->customer, '2026-11-01', Shift::Morning, MilkType::Cow, '1.000'); // 70.00

    // A November-only view must not look settled just because October is excluded.
    $statement = app(CustomerLedgerService::class)->statement($this->customer, '2026-11-01', '2026-11-30');

    expect($statement['opening'])->toBe('140.00')
        ->and($statement['closing'])->toBe('210.00')
        ->and($statement['totals']['sales'])->toBe('70.00');
});

test('the monthly summary reports milk, sales, payments and outstanding separately', function () {
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '10.000'); // 700.00

    $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '400.00',
        'financial_account_id' => $this->cash->id,
    ]);

    $summary = app(CustomerLedgerService::class)->monthlySummary($this->customer, 2026, 10);

    expect($summary['milk'])->toBe('10.000')
        ->and($summary['sales'])->toBe('700.00')
        ->and($summary['payments'])->toBe('400.00')
        ->and($summary['outstanding_in_period'])->toBe('300.00')
        ->and($summary['outstanding_total'])->toBe('300.00')
        // Sales are not cash: the two figures are reported apart.
        ->and($summary['sales'])->not->toBe($summary['payments']);
});

test('cancelled sales and payments are excluded from the ledger', function () {
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->sell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, '2.000');

    $payment = $this->record->handle($this->customer, [
        'payment_date' => $this->date, 'amount' => '100.00',
        'financial_account_id' => $this->cash->id,
    ]);

    // Withdraw the evening delivery and the payment.
    $this->sell->handle($this->customer, $this->date, Shift::Evening, MilkType::Cow, null);
    app(CancelBuyerPayment::class)->handle($payment, 'Recorded in error');

    $statement = app(CustomerLedgerService::class)->statement($this->customer, $this->date, $this->date);

    expect($statement['rows'])->toHaveCount(1)
        ->and($statement['rows']->first()->morningQuantity)->toBe('2.000')
        ->and($statement['rows']->first()->eveningQuantity)->toBe('0.000')
        ->and($statement['totals']['payments'])->toBe('0.00')
        ->and($statement['closing'])->toBe('140.00');
});

test('the ledger respects a date filter', function () {
    $this->sell->handle($this->customer, $this->date, Shift::Morning, MilkType::Cow, '2.000');

    seedProductionFor($this->farm, '2026-11-05');
    $this->sell->handle($this->customer, '2026-11-05', Shift::Morning, MilkType::Cow, '3.000');

    $october = app(CustomerLedgerService::class)->statement($this->customer, '2026-10-01', '2026-10-31');

    expect($october['rows'])->toHaveCount(1)
        ->and($october['totals']['milk'])->toBe('2.000');
});

test('one customer ledger never shows another customer money', function () {
    $other = directCustomer($this->business, [MilkType::Cow]);

    $this->sell->handle($other, $this->date, Shift::Morning, MilkType::Cow, '5.000');

    $statement = app(CustomerLedgerService::class)->statement($this->customer, $this->date, $this->date);

    expect($statement['rows'])->toBeEmpty()
        ->and($statement['totals']['sales'])->toBe('0.00')
        ->and($this->outstanding->outstandingFor($this->customer))->toBe('0.00');
});

test('milk by type reports quantities and amounts apart', function () {
    $both = directCustomer($this->business, [MilkType::Cow, MilkType::Buffalo]);
    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');

    $this->sell->handle($both, $this->date, Shift::Morning, MilkType::Cow, '2.000');
    $this->sell->handle($both, $this->date, Shift::Morning, MilkType::Buffalo, '1.000');

    $byType = app(CustomerLedgerService::class)->milkByType($both, $this->date, $this->date);

    expect($byType['cow']['quantity'])->toBe('2.000')
        ->and($byType['cow']['amount'])->toBe('140.00')
        ->and($byType['buffalo']['quantity'])->toBe('1.000')
        ->and($byType['buffalo']['amount'])->toBe('85.00');
});
