<?php

use App\Actions\Milk\SaveCustomerDailyDeliveries;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\MilkSale;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\Quantity;

/*
 * Save Day is one business operation.
 *
 * A day that saved ninety-nine cells and failed on the hundredth would leave the
 * operator with no way to tell which half went in — and the half that did would be
 * real money against real customers. So the whole request lives or dies together.
 *
 * Failures are injected with Eloquent model events registered inside the test. No
 * production code knows about them, and no test-only "make this fail" flag ships.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '100.000', buffalo: '100.000');

    $this->action = app(SaveCustomerDailyDeliveries::class);
    $this->engine = app(CalculateMilkReconciliation::class);
    $this->outstanding = app(BuyerOutstandingService::class);

    $this->actingAs(superAdmin());

    $this->row = fn (int $buyerId, ?string $morning, ?string $evening = null): array => [
        'buyer_id' => $buyerId,
        'milk_type' => 'cow',
        'morning' => $morning,
        'evening' => $evening,
    ];
});

afterEach(function (): void {
    // Model event listeners are static and must not leak into the next test.
    AuditLog::flushEventListeners();
    MilkSale::flushEventListeners();

    AuditLog::bootTraits();
    MilkSale::bootTraits();
});

/** Makes the nth insert of a model throw, so a failure can land mid-day. */
function failOnNthInsert(string $modelClass, int $n): void
{
    $seen = 0;

    $modelClass::creating(function () use (&$seen, $n): void {
        $seen++;

        if ($seen >= $n) {
            throw new RuntimeException('Injected failure');
        }
    });
}

test('a failure on the third cell leaves the first two unwritten', function () {
    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);
    $c = directCustomer($this->business, [MilkType::Cow]);

    // The third sale insert throws.
    failOnNthInsert(MilkSale::class, 3);

    expect(fn () => $this->action->handle($this->date, [
        ($this->row)($a->id, '1.000'),
        ($this->row)($b->id, '2.000'),
        ($this->row)($c->id, '3.000'),
    ], $this->farm->id))->toThrow(RuntimeException::class);

    expect(MilkSale::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', 'milk_sale')->count())->toBe(0);
});

test('a failed save leaves the milk unallocated and the customers owing nothing', function () {
    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);

    failOnNthInsert(MilkSale::class, 2);

    expect(fn () => $this->action->handle($this->date, [
        ($this->row)($a->id, '1.000'),
        ($this->row)($b->id, '2.000'),
    ], $this->farm->id))->toThrow(RuntimeException::class);

    // The observable consequences, not merely the absent rows.
    $result = $this->engine->forShift($this->farm->id, $this->date, Shift::Morning, MilkType::Cow);

    expect($result->sales->total)->toBe('0.000')
        ->and($result->remaining)->toBe('100.000');

    expectMoney($this->outstanding->breakdownFor($a)['outstanding'], '0.00');
    expectMoney($this->outstanding->breakdownFor($b)['outstanding'], '0.00');
});

test('a failed audit write takes the sale down with it', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    AuditLog::creating(function (): never {
        throw new RuntimeException('Injected failure');
    });

    expect(fn () => $this->action->handle(
        $this->date, [($this->row)($customer->id, '1.000')], $this->farm->id
    ))->toThrow(RuntimeException::class);

    // A delivery with no audit record is the one row that must never exist.
    expect(MilkSale::query()->count())->toBe(0);
});

test('a failure while correcting a saved day leaves the saved figures intact', function () {
    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);

    $this->action->handle($this->date, [
        ($this->row)($a->id, '1.000'),
        ($this->row)($b->id, '2.000'),
    ], $this->farm->id);

    $before = MilkSale::query()->orderBy('buyer_id')->pluck('quantity', 'buyer_id')->toArray();
    $audits = AuditLog::query()->count();

    // Now the second update throws partway through a correction of both.
    $seen = 0;
    MilkSale::updating(function () use (&$seen): void {
        $seen++;

        if ($seen >= 2) {
            throw new RuntimeException('Injected failure');
        }
    });

    expect(fn () => $this->action->handle($this->date, [
        ($this->row)($a->id, '5.000'),
        ($this->row)($b->id, '6.000'),
    ], $this->farm->id))->toThrow(RuntimeException::class);

    expect(MilkSale::query()->orderBy('buyer_id')->pluck('quantity', 'buyer_id')->toArray())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($audits);
});

test('a failure during a cancellation leaves the delivery standing', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $this->action->handle($this->date, [($this->row)($customer->id, '2.000')], $this->farm->id);

    MilkSale::updating(function (): never {
        throw new RuntimeException('Injected failure');
    });

    expect(fn () => $this->action->handle(
        $this->date, [($this->row)($customer->id, null)], $this->farm->id
    ))->toThrow(RuntimeException::class);

    $sale = MilkSale::query()->firstOrFail();

    expect($sale->status)->toBe(TransactionStatus::Active)
        ->and(Quantity::of($sale->quantity))->toBe('2.000');

    expectMoney($this->outstanding->breakdownFor($customer)['outstanding'], '140.00');
});

test('the request is rolled back through the HTTP layer too, not only the action', function () {
    $a = directCustomer($this->business, [MilkType::Cow]);
    $b = directCustomer($this->business, [MilkType::Cow]);

    failOnNthInsert(MilkSale::class, 2);

    // A thrown RuntimeException surfaces as a 500 rather than a validation error,
    // and the day must still be whole afterwards.
    $this->withoutExceptionHandling()
        ->postJson(route('milk.customer-entry.store'), [
            'date' => $this->date,
            'rows' => [($this->row)($a->id, '1.000'), ($this->row)($b->id, '2.000')],
        ]);
})->throws(RuntimeException::class);

test('nothing survived that failed HTTP save', function () {
    // Separate test, because the throwing request above ends its own example.
    expect(MilkSale::query()->count())->toBe(0);
});
