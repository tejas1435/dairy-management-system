<?php

use App\Actions\Customers\CancelCustomerPause;
use App\Actions\Customers\CreateCustomerPause;
use App\Enums\MilkType;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\CustomerPause;
use App\Services\Customers\CustomerEligibilityService;
use App\Services\Customers\CustomerPauseService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * Customer pauses, and the eligibility questions that depend on them.
 *
 * Two things carry the design: overlapping active pauses are refused, because two
 * overlapping periods make "why is this customer paused" unanswerable; and the
 * paused-on-a-date question is one service method, because the Pass 2 grid will ask
 * it several hundred times per page load and must get the same answer the sale action
 * gets.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->admin = superAdmin();
    $this->customer = directCustomer($this->business, [MilkType::Cow]);
    $this->pauses = app(CustomerPauseService::class);
    $this->create = app(CreateCustomerPause::class);

    $this->actingAs($this->admin);
});

/*
|--------------------------------------------------------------------------
| A. Schema
|--------------------------------------------------------------------------
*/

test('pause dates are DATE columns, so no timezone arithmetic is involved', function () {
    expect(Schema::getColumnType('customer_pauses', 'start_date'))->toBe('date')
        ->and(Schema::getColumnType('customer_pauses', 'end_date'))->toBe('date');
});

test('a pause end date is nullable, meaning open-ended', function () {
    $pause = $this->create->handle($this->customer, '2026-10-01', null);

    expect($pause->end_date)->toBeNull()
        ->and($pause->isOpenEnded())->toBeTrue();
});

test('a reason is optional, as the specification says', function () {
    $pause = $this->create->handle($this->customer, '2026-10-01', '2026-10-05', null);

    expect($pause->reason)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| B. Overlap prevention
|--------------------------------------------------------------------------
*/

test('an overlapping pause is refused', function () {
    $this->create->handle($this->customer, '2026-10-01', '2026-10-05');

    // 03-10 to 07-10 overlaps 01-10 to 05-10.
    expect(fn () => $this->create->handle($this->customer, '2026-10-03', '2026-10-07'))
        ->toThrow(ValidationException::class);

    expect(CustomerPause::query()->count())->toBe(1);
});

test('adjacent pauses are allowed', function () {
    $this->create->handle($this->customer, '2026-10-01', '2026-10-05');
    // Starts the day after the first ends.
    $this->create->handle($this->customer, '2026-10-06', '2026-10-10');

    expect(CustomerPause::query()->count())->toBe(2);
});

test('a pause entirely inside an existing one is refused', function () {
    $this->create->handle($this->customer, '2026-10-01', '2026-10-31');

    expect(fn () => $this->create->handle($this->customer, '2026-10-10', '2026-10-12'))
        ->toThrow(ValidationException::class);
});

test('a pause entirely containing an existing one is refused', function () {
    $this->create->handle($this->customer, '2026-10-10', '2026-10-12');

    expect(fn () => $this->create->handle($this->customer, '2026-10-01', '2026-10-31'))
        ->toThrow(ValidationException::class);
});

test('an open-ended pause blocks anything starting after it', function () {
    $this->create->handle($this->customer, '2026-10-01', null);

    expect(fn () => $this->create->handle($this->customer, '2026-12-01', '2026-12-05'))
        ->toThrow(ValidationException::class);
});

test('a new open-ended pause is refused when it would swallow a later one', function () {
    $this->create->handle($this->customer, '2026-12-01', '2026-12-05');

    expect(fn () => $this->create->handle($this->customer, '2026-10-01', null))
        ->toThrow(ValidationException::class);
});

test('a cancelled pause does not block a new one', function () {
    $pause = $this->create->handle($this->customer, '2026-10-01', '2026-10-05');
    app(CancelCustomerPause::class)->handle($pause, 'Plans changed after all');

    // The dates are free again, because the withdrawn pause no longer applies.
    $replacement = $this->create->handle($this->customer, '2026-10-03', '2026-10-07');

    expect($replacement->exists)->toBeTrue()
        ->and(CustomerPause::query()->count())->toBe(2)
        ->and(CustomerPause::query()->active()->count())->toBe(1);
});

test('another customer pause does not block this one', function () {
    $other = directCustomer($this->business, [MilkType::Cow]);

    $this->create->handle($other, '2026-10-01', '2026-10-05');
    $mine = $this->create->handle($this->customer, '2026-10-01', '2026-10-05');

    expect($mine->exists)->toBeTrue();
});

test('the overlap refusal names the clashing period', function () {
    $this->create->handle($this->customer, '2026-10-01', '2026-10-05');

    try {
        $this->create->handle($this->customer, '2026-10-03', '2026-10-07');
        $this->fail('Expected the overlapping pause to be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['start_date'][0])
            ->toBe(__('customers.errors.pause_overlaps', ['from' => '01-10-2026', 'to' => '05-10-2026']));
    }
});

test('an end date before the start date is refused', function () {
    expect(fn () => $this->create->handle($this->customer, '2026-10-10', '2026-10-05'))
        ->toThrow(ValidationException::class);

    expect(CustomerPause::query()->count())->toBe(0);
});

test('the form refuses an end date before the start date too', function () {
    $this->post(route('customers.pauses.store', $this->customer), [
        'start_date' => '2026-10-10',
        'end_date' => '2026-10-05',
    ])->assertSessionHasErrors('end_date');

    expect(CustomerPause::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. The paused-on-a-date question
|--------------------------------------------------------------------------
*/

test('a date inside the pause is paused, and the boundaries are inclusive', function (string $date, bool $paused) {
    $this->create->handle($this->customer, '2026-10-05', '2026-10-10');

    expect($this->pauses->isPausedOn($this->customer, $date))->toBe($paused);
})->with([
    'day before' => ['2026-10-04', false],
    'first day' => ['2026-10-05', true],
    'middle' => ['2026-10-07', true],
    'last day' => ['2026-10-10', true],
    'day after' => ['2026-10-11', false],
]);

test('an open-ended pause covers every date from its start', function () {
    $this->create->handle($this->customer, '2026-10-05', null);

    expect($this->pauses->isPausedOn($this->customer, '2026-10-04'))->toBeFalse()
        ->and($this->pauses->isPausedOn($this->customer, '2026-10-05'))->toBeTrue()
        ->and($this->pauses->isPausedOn($this->customer, '2030-01-01'))->toBeTrue();
});

test('a cancelled pause stops answering yes immediately', function () {
    $pause = $this->create->handle($this->customer, '2026-10-05', '2026-10-10');

    expect($this->pauses->isPausedOn($this->customer, '2026-10-07'))->toBeTrue();

    app(CancelCustomerPause::class)->handle($pause, 'Withdrawn after review');

    expect($this->pauses->isPausedOn($this->customer, '2026-10-07'))->toBeFalse();
});

test('the batch lookup agrees with the per-customer one', function () {
    $paused = directCustomer($this->business, [MilkType::Cow]);
    $free = directCustomer($this->business, [MilkType::Cow]);

    $this->create->handle($paused, '2026-10-05', '2026-10-10');

    $map = $this->pauses->pausedMapFor([$paused->id, $free->id, $this->customer->id], '2026-10-07');

    expect($map[$paused->id])->toBeTrue()
        ->and($map[$free->id])->toBeFalse()
        ->and($map[$this->customer->id])->toBeFalse()
        // And the same answers the single-customer method gives.
        ->and($map[$paused->id])->toBe($this->pauses->isPausedOn($paused, '2026-10-07'))
        ->and($map[$free->id])->toBe($this->pauses->isPausedOn($free, '2026-10-07'));
});

test('the batch lookup is one query however many customers are asked about', function () {
    $customers = collect(range(1, 5))->map(fn () => directCustomer($this->business, [MilkType::Cow]));

    foreach ($customers as $customer) {
        $this->create->handle($customer, '2026-10-05', '2026-10-10');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->pauses->pausedMapFor($customers->pluck('id')->all(), '2026-10-07');

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBe(1);
});

test('the timeline separates current, upcoming, past and withdrawn', function () {
    $today = '2026-10-15';

    $current = $this->create->handle($this->customer, '2026-10-14', '2026-10-16');
    $upcoming = $this->create->handle($this->customer, '2026-11-01', '2026-11-05');
    $past = $this->create->handle($this->customer, '2026-09-01', '2026-09-05');
    $withdrawn = $this->create->handle($this->customer, '2026-12-01', '2026-12-05');

    app(CancelCustomerPause::class)->handle($withdrawn, 'No longer going away');

    $timeline = $this->pauses->timelineFor($this->customer->fresh(), $today);

    expect($timeline['current']?->id)->toBe($current->id)
        ->and($timeline['upcoming']->pluck('id')->all())->toBe([$upcoming->id])
        ->and($timeline['past']->pluck('id')->all())->toBe([$past->id])
        ->and($timeline['cancelled']->pluck('id')->all())->toBe([$withdrawn->id]);
});

/*
|--------------------------------------------------------------------------
| D. Cancellation
|--------------------------------------------------------------------------
*/

test('cancelling a pause keeps the row with its reason', function () {
    $pause = $this->create->handle($this->customer, '2026-10-05', '2026-10-10', 'Going away');

    app(CancelCustomerPause::class)->handle($pause, 'Trip was called off');

    $pause->refresh();

    expect(CustomerPause::query()->count())->toBe(1)
        ->and($pause->status)->toBe(TransactionStatus::Cancelled)
        ->and($pause->cancellation_reason)->toBe('Trip was called off')
        ->and($pause->cancelled_by)->toBe($this->admin->id)
        // The original dates and reason are untouched.
        ->and($pause->reason)->toBe('Going away')
        ->and($pause->start_date->toDateString())->toBe('2026-10-05');
});

test('cancelling twice is refused', function () {
    $pause = $this->create->handle($this->customer, '2026-10-05', '2026-10-10');

    app(CancelCustomerPause::class)->handle($pause, 'First withdrawal');

    expect(fn () => app(CancelCustomerPause::class)->handle($pause->fresh(), 'Second attempt'))
        ->toThrow(ValidationException::class);

    expect($pause->fresh()->cancellation_reason)->toBe('First withdrawal');
});

test('cancelling requires a usable reason', function (string $reason) {
    $pause = $this->create->handle($this->customer, '2026-10-05', '2026-10-10');

    $this->put(route('customers.pauses.cancel', $pause), ['cancellation_reason' => $reason])
        ->assertSessionHasErrors('cancellation_reason');

    expect($pause->fresh()->isCancelled())->toBeFalse();
})->with(['empty' => [''], 'too short' => ['no']]);

test('there is no route for deleting a pause', function () {
    $destructive = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_contains($route->uri(), 'pause'));

    expect($destructive)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| E. Audit
|--------------------------------------------------------------------------
*/

test('creating and cancelling a pause are both audited', function () {
    $pause = $this->create->handle($this->customer, '2026-10-05', '2026-10-10', 'Going away');
    app(CancelCustomerPause::class)->handle($pause, 'Trip was called off');

    $logs = AuditLog::query()->where('auditable_type', 'customer_pause')
        ->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action->value)->toBe('created')
        ->and($logs[0]->new_values['start_date'])->toBe('2026-10-05')
        ->and($logs[0]->new_values['reason'])->toBe('Going away')
        ->and($logs[1]->action->value)->toBe('cancelled')
        ->and($logs[1]->new_values['cancellation_reason'])->toBe('Trip was called off');
});

/*
|--------------------------------------------------------------------------
| F. Eligibility: start date, pause, preference and archive together
|--------------------------------------------------------------------------
*/

test('a customer who has not started is not deliverable', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['start_date' => '2026-11-01']);

    $eligibility = app(CustomerEligibilityService::class)->for($customer, '2026-10-15');

    expect($eligibility->hasStarted)->toBeFalse()
        ->and($eligibility->isDeliverable())->toBeFalse()
        ->and($eligibility->ineligibleReason())->toBe('not_started');
});

test('the start date is inclusive', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['start_date' => '2026-11-01']);

    expect($customer->hasStartedBy('2026-10-31'))->toBeFalse()
        ->and($customer->hasStartedBy('2026-11-01'))->toBeTrue()
        ->and($customer->hasStartedBy('2026-11-02'))->toBeTrue();
});

test('a null start date means from the beginning', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['start_date' => null]);

    expect($customer->hasStartedBy('2000-01-01'))->toBeTrue()
        ->and(app(CustomerEligibilityService::class)->for($customer, '2000-01-01')->isDeliverable())
        ->toBeTrue();
});

test('an archived customer is not deliverable', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], ['is_active' => false]);

    $eligibility = app(CustomerEligibilityService::class)->for($customer, now()->toDateString());

    expect($eligibility->isDeliverable())->toBeFalse()
        ->and($eligibility->ineligibleReason())->toBe('archived');
});

test('a customer with no active preference is not deliverable', function () {
    $customer = directCustomer($this->business, []);

    $eligibility = app(CustomerEligibilityService::class)->for($customer, now()->toDateString());

    expect($eligibility->isDeliverable())->toBeFalse()
        ->and($eligibility->ineligibleReason())->toBe('no_preference');
});

test('a paused customer is not deliverable but is still listed', function () {
    $this->create->handle($this->customer, '2026-10-05', '2026-10-10');

    $eligibility = app(CustomerEligibilityService::class)->for($this->customer->fresh(), '2026-10-07');

    expect($eligibility->isPaused)->toBeTrue()
        ->and($eligibility->isDeliverable())->toBeFalse()
        ->and($eligibility->ineligibleReason())->toBe('paused')
        // The pause is available so a screen can say why.
        ->and($eligibility->pause)->not->toBeNull();

    // And the batch query still returns them, marked, rather than dropping them —
    // MASTER_SPEC section 18 wants paused customers visibly marked.
    $forDate = app(CustomerEligibilityService::class)->forDate('2026-10-07');

    expect($forDate)->toHaveKey($this->customer->id)
        ->and($forDate[$this->customer->id]->isPaused)->toBeTrue();
});

test('the batch eligibility query excludes who it should', function () {
    $deliverable = directCustomer($this->business, [MilkType::Cow]);
    $archived = directCustomer($this->business, [MilkType::Cow], ['is_active' => false]);
    $notStarted = directCustomer($this->business, [MilkType::Cow], ['start_date' => '2030-01-01']);
    $noPreference = directCustomer($this->business, []);

    $forDate = app(CustomerEligibilityService::class)->forDate('2026-10-07');

    expect($forDate)->toHaveKey($deliverable->id)
        ->and($forDate)->not->toHaveKey($archived->id)
        ->and($forDate)->not->toHaveKey($notStarted->id)
        ->and($forDate)->not->toHaveKey($noPreference->id);
});

test('a milk type the customer does not take is reported as not taken', function () {
    $customer = directCustomer($this->business, [MilkType::Cow]);

    $eligibility = app(CustomerEligibilityService::class)->for($customer, now()->toDateString());

    expect($eligibility->takes(MilkType::Cow))->toBeTrue()
        ->and($eligibility->takes(MilkType::Buffalo))->toBeFalse()
        ->and($eligibility->milkTypes())->toBe([MilkType::Cow]);
});

test('the delivery note travels with the eligibility, for the grid to display', function () {
    $customer = directCustomer($this->business, [MilkType::Cow], [
        'delivery_note' => 'Leave with the neighbour',
    ]);

    $eligibility = app(CustomerEligibilityService::class)->for($customer, now()->toDateString());

    expect($eligibility->deliveryNote)->toBe('Leave with the neighbour');
});
