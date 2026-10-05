<?php

use App\Actions\Pricing\DeleteFuturePriceRule;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Models\SalesChannel;
use App\Services\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $this->action = app(SetMilkPrice::class);
    $this->actingAs(superAdmin());
});

/** The cow price rules for the business, oldest first. */
function cowRules(): Collection
{
    return MilkPriceRule::query()
        ->where('milk_type', MilkType::Cow->value)
        ->orderBy('effective_from')
        ->get();
}

/*
|--------------------------------------------------------------------------
| MilkType value object
|--------------------------------------------------------------------------
*/

test('the milk type enum accepts only cow and buffalo', function () {
    expect(MilkType::values())->toBe(['cow', 'buffalo'])
        ->and(MilkType::tryFrom('goat'))->toBeNull()
        ->and(MilkType::from('cow'))->toBe(MilkType::Cow);
});

test('an unsupported milk type is rejected by the form', function () {
    $this->post(route('settings.milk-prices.store'), [
        'milk_type' => 'goat',
        'rate' => '70.00',
        'effective_from' => '2026-09-01',
    ])->assertSessionHasErrors('milk_type');

    expect(MilkPriceRule::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| First price, and changing it
|--------------------------------------------------------------------------
*/

test('the first price opens an open-ended period', function () {
    $rule = $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');

    expect($rule->effective_from->toDateString())->toBe('2026-09-01')
        ->and($rule->effective_to)->toBeNull();

    expectMoney($rule->rate, '70.00');
});

test('changing the price closes the old period and opens a new one without rewriting history', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');
    $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-10-01');

    $rules = cowRules();

    expect($rules)->toHaveCount(2);

    // September keeps its rate and gains an end date the day before October.
    expectMoney($rules[0]->rate, '70.00');
    expect($rules[0]->effective_from->toDateString())->toBe('2026-09-01')
        ->and($rules[0]->effective_to->toDateString())->toBe('2026-09-30');

    expectMoney($rules[1]->rate, '75.00');
    expect($rules[1]->effective_from->toDateString())->toBe('2026-10-01')
        ->and($rules[1]->effective_to)->toBeNull();
});

test('the old rate value is never altered by a later price change', function () {
    $first = $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');

    $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-10-01');
    $this->action->forBusiness($this->business, MilkType::Cow, '80.00', '2026-11-01');

    expectMoney($first->fresh()->rate, '70.00');
});

test('a zero or negative rate is refused', function (string $rate) {
    expect(fn () => $this->action->forBusiness($this->business, MilkType::Cow, $rate, '2026-09-01'))
        ->toThrow(ValidationException::class);

    expect(MilkPriceRule::query()->count())->toBe(0);
})->with(['0', '0.00', '-1.00', '-0.01']);

test('cow and buffalo prices are independent', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');
    $this->action->forBusiness($this->business, MilkType::Buffalo, '85.00', '2026-09-01');

    $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-10-01');

    $buffalo = MilkPriceRule::query()->where('milk_type', MilkType::Buffalo->value)->get();

    expect($buffalo)->toHaveCount(1)
        ->and($buffalo[0]->effective_to)->toBeNull();

    expectMoney($buffalo[0]->rate, '85.00');
});

test('one business never resolves a price from another', function () {
    $other = Business::factory()->create();

    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');
    $this->action->forBusiness($other, MilkType::Cow, '999.00', '2026-09-01');

    $resolver = app(PriceResolver::class);

    expectMoney($resolver->resolveDefault($this->business->id, MilkType::Cow, '2026-09-15')->rate(), '70.00');
    expectMoney($resolver->resolveDefault($other->id, MilkType::Cow, '2026-09-15')->rate(), '999.00');
});

/*
|--------------------------------------------------------------------------
| Overlap prevention
|--------------------------------------------------------------------------
*/

test('a second period starting on the same day is refused', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');

    expect(fn () => $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-09-01'))
        ->toThrow(ValidationException::class);

    expect(cowRules())->toHaveCount(1);
});

test('a period starting before the latest one is refused', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');

    // Backdating would place a period inside one that already exists.
    expect(fn () => $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-08-15'))
        ->toThrow(ValidationException::class);

    expect(cowRules())->toHaveCount(1);
});

test('a period starting inside a closed period is refused', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');
    $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-11-01');

    // 2026-10-01 falls inside the now-closed September to October period.
    expect(fn () => $this->action->forBusiness($this->business, MilkType::Cow, '72.00', '2026-10-01'))
        ->toThrow(ValidationException::class);

    expect(cowRules())->toHaveCount(2);
});

test('adjacent periods are allowed: one ends the day before the next begins', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');
    $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-10-01');

    $rules = cowRules();

    // No day belongs to two periods, and no day is left without one.
    expect($rules[0]->effective_to->toDateString())->toBe('2026-09-30')
        ->and($rules[1]->effective_from->toDateString())->toBe('2026-10-01');

    $resolver = app(PriceResolver::class);
    expectMoney($resolver->resolveDefault($this->business->id, MilkType::Cow, '2026-09-30')->rate(), '70.00');
    expectMoney($resolver->resolveDefault($this->business->id, MilkType::Cow, '2026-10-01')->rate(), '75.00');
});

test('the database refuses two rules starting on the same day even outside the action', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');

    expect(fn () => MilkPriceRule::create([
        'business_id' => $this->business->id,
        'milk_type' => MilkType::Cow->value,
        'rate' => '99.00',
        'effective_from' => '2026-09-01',
    ]))->toThrow(QueryException::class);
});

test('no date resolves to two different rates', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', '2026-09-01');
    $this->action->forBusiness($this->business, MilkType::Cow, '75.00', '2026-10-01');
    $this->action->forBusiness($this->business, MilkType::Cow, '80.00', '2026-11-01');

    foreach (['2026-09-01', '2026-09-30', '2026-10-01', '2026-10-31', '2026-11-01', '2027-06-01'] as $date) {
        $matching = MilkPriceRule::query()
            ->where('business_id', $this->business->id)
            ->where('milk_type', MilkType::Cow->value)
            ->effectiveOn($date)
            ->count();

        expect($matching)->toBe(1, "Date {$date} matched {$matching} periods");
    }
});

/*
|--------------------------------------------------------------------------
| Withdrawing a future period
|--------------------------------------------------------------------------
*/

test('a future period can be withdrawn and reopens the period before it', function () {
    $this->action->forBusiness($this->business, MilkType::Cow, '70.00', now()->subMonth()->toDateString());
    $future = $this->action->forBusiness($this->business, MilkType::Cow, '75.00', now()->addMonth()->toDateString());

    $previous = cowRules()->first();
    expect($previous->effective_to)->not->toBeNull();

    app(DeleteFuturePriceRule::class)->handle($future);

    expect(cowRules())->toHaveCount(1)
        // No gap is left where no price resolves.
        ->and($previous->fresh()->effective_to)->toBeNull();
});

test('a period that has already taken effect cannot be withdrawn', function () {
    // It may already have priced a sale; deleting it would change the past.
    $rule = $this->action->forBusiness($this->business, MilkType::Cow, '70.00', now()->subDay()->toDateString());

    expect(fn () => app(DeleteFuturePriceRule::class)->handle($rule))
        ->toThrow(ValidationException::class);

    expect(MilkPriceRule::query()->count())->toBe(1);
});

test('the form refuses to withdraw a rule from another business', function () {
    $other = Business::factory()->create();
    $foreign = MilkPriceRule::factory()->for($other)->period(now()->addMonth()->toDateString())->create();

    $this->delete(route('settings.milk-prices.destroy', $foreign))->assertSessionHasErrors('rule');

    expect(MilkPriceRule::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('there is no way to edit a rate in place', function () {
    // The only correction is a new period; that is what keeps history honest.
    $names = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName())->filter()
        ->filter(fn ($n) => str_contains((string) $n, 'milk-prices'));

    expect($names->values()->all())->toBe([
        'settings.milk-prices.index',
        'settings.milk-prices.store',
        'settings.milk-prices.destroy',
    ]);
});

/*
|--------------------------------------------------------------------------
| Buyer overrides
|--------------------------------------------------------------------------
*/

test('a buyer override period behaves like the business one', function () {
    $channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $buyer = Buyer::factory()->inChannel($channel)->create();

    $this->action->forBuyer($buyer, MilkType::Cow, '72.00', '2026-09-01');
    $this->action->forBuyer($buyer, MilkType::Cow, '74.00', '2026-10-01');

    $rules = BuyerPriceRule::query()->orderBy('effective_from')->get();

    expect($rules)->toHaveCount(2)
        ->and($rules[0]->effective_to->toDateString())->toBe('2026-09-30')
        ->and($rules[1]->effective_to)->toBeNull();
});

test('buyer override periods cannot overlap for the same buyer and milk type', function () {
    $channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $buyer = Buyer::factory()->inChannel($channel)->create();

    $this->action->forBuyer($buyer, MilkType::Cow, '72.00', '2026-09-01');

    expect(fn () => $this->action->forBuyer($buyer, MilkType::Cow, '73.00', '2026-09-01'))
        ->toThrow(ValidationException::class);

    expect(BuyerPriceRule::query()->count())->toBe(1);
});

test('two different buyers may hold different simultaneous rates', function () {
    $channel = SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail();
    $one = Buyer::factory()->inChannel($channel)->create();
    $two = Buyer::factory()->inChannel($channel)->create();

    $this->action->forBuyer($one, MilkType::Cow, '72.00', '2026-09-01');
    $this->action->forBuyer($two, MilkType::Cow, '68.00', '2026-09-01');

    $resolver = app(PriceResolver::class);

    expectMoney($resolver->resolve($one, MilkType::Cow, '2026-09-15')->rate(), '72.00');
    expectMoney($resolver->resolve($two, MilkType::Cow, '2026-09-15')->rate(), '68.00');
});
