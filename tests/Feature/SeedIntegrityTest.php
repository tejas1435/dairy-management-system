<?php

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\SettlementStatus;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\BuyerPriceRule;
use App\Models\BuyerSettlement;
use App\Models\CustomerPause;
use App\Models\CustomerPreference;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\MilkPriceRule;
use App\Models\MilkProduction;
use App\Models\MilkSale;
use App\Models\SalesChannel;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Customers\CustomerPauseService;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Services\PriceResolver;
use App\Support\Quantity;
use Database\Seeders\CustomerSalesSeeder;
use Database\Seeders\DatabaseSeeder;

/*
 * The development seed, run end to end against the test schema.
 *
 * The seed is the first thing anybody sees on a fresh installation, and it is the
 * one dataset in the project that is written by code rather than by a person. If it
 * is internally inconsistent — a shift distributing more milk than it produced, an
 * outstanding balance that does not equal sales minus payments, a receipt credited
 * to an account twice — then every screen a newcomer opens is lying to them, and no
 * other test would catch it, because tests build their own data with factories.
 *
 * So the properties asserted here are the arithmetic ones, not the counts. A count
 * changes whenever somebody adds a demo customer; the arithmetic must never change.
 */

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->business = Business::query()->firstOrFail();
    $this->farm = $this->business->primaryFarm();
    $this->engine = app(CalculateMilkReconciliation::class);
    $this->outstanding = app(BuyerOutstandingService::class);
});

/*
|--------------------------------------------------------------------------
| A. The seed runs, and produces the shape it claims
|--------------------------------------------------------------------------
*/

test('the complete seeding path runs against the test schema', function () {
    expect(Buyer::query()->directCustomers()->count())->toBe(20)
        ->and(CustomerPreference::query()->count())->toBe(24)
        ->and(CustomerPause::query()->count())->toBe(3);

    // Two customer overrides from Phase 4, one vendor rate from Phase 5.
    expect(BuyerPriceRule::query()->count())->toBe(3);

    // Phase 5's trade buyers.
    expect(Buyer::query()->mandalis()->count())->toBe(1)
        ->and(Buyer::query()->vendors()->count())->toBe(2)
        ->and(Buyer::query()->customChannel()->count())->toBe(1);

    expect(MilkSale::query()->active()->count())->toBeGreaterThan(0)
        ->and(BuyerPayment::query()->active()->count())->toBeGreaterThan(0);
});

test('each seeded sale carries the source its own workflow owns', function () {
    /*
     * Through Phase 4 this asserted that every sale was a customer grid sale, because
     * the grid was the only workflow there was. Phase 5 added three more, so the guard
     * now checks the thing it was really protecting: a sale's source and its buyer's
     * channel agree, which means no sale was written through the wrong workflow.
     */
    $expectedBySlug = [
        SalesChannel::DIRECT_CUSTOMER => SaleSource::CustomerDailyGrid,
        SalesChannel::MANDALI => SaleSource::MandaliDelivery,
        SalesChannel::VENDOR => SaleSource::VendorSale,
    ];

    foreach (MilkSale::query()->with('buyer.salesChannel')->get() as $sale) {
        $slug = (string) $sale->buyer->channelSlug();
        $expected = $expectedBySlug[$slug] ?? SaleSource::GenericSale;

        expect($sale->source)->toBe(
            $expected,
            "A {$slug} sale was seeded with source {$sale->source->value}."
        );

        expect($sale->farm_id)->toBe($this->farm->id);
    }
});

test('every channel has seeded sales through its own workflow', function () {
    // The inverse of the Phase 4 guard: these channels were empty then, and are
    // deliberately populated now.
    foreach (SaleSource::cases() as $source) {
        expect(MilkSale::query()->active()->fromSource($source)->count())
            ->toBeGreaterThan(0, "No active sale was seeded for {$source->value}.");
    }

    // Receipts exist for a customer and for a trade buyer.
    expect(BuyerPayment::query()->active()->whereHas('buyer', fn ($q) => $q->directCustomers())->count())
        ->toBeGreaterThan(0)
        ->and(BuyerPayment::query()->active()->whereHas('buyer', fn ($q) => $q->mandalis())->count())
        ->toBeGreaterThan(0);
});

test('the seeded settlement is a draft, with no accounting effect', function () {
    /*
     * Deliberately not finalized. A finalized settlement would post a receivable
     * adjustment nobody asked for, and would close its period — so a developer
     * correcting a seeded delivery would be refused until they cancelled a settlement
     * they never created.
     */
    $settlements = BuyerSettlement::query()->get();

    expect($settlements)->toHaveCount(1);

    $settlement = $settlements->first();

    expect($settlement->status)->toBe(SettlementStatus::Draft)
        ->and($settlement->milk_quantity)->toBeNull()
        ->and($settlement->expected_amount)->toBeNull()
        ->and($settlement->difference)->toBeNull()
        // A statement is set, so finalizing it demonstrates a real difference.
        ->and($settlement->hasStatement())->toBeTrue();

    expect(BuyerBalanceAdjustment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| B. The milk adds up
|--------------------------------------------------------------------------
*/

test('no seeded shift distributes more milk than it had', function () {
    $dates = MilkProduction::query()->pluck('production_date')
        ->map(fn ($date): string => $date->toDateString())->unique();

    // Plus any date that has a sale, in case one was ever seeded without production.
    $dates = $dates->merge(
        MilkSale::query()->pluck('sale_date')->map(fn ($date): string => $date->toDateString())
    )->unique();

    expect($dates)->not->toBeEmpty();

    foreach ($dates as $date) {
        foreach (Shift::cases() as $shift) {
            foreach (MilkType::cases() as $milkType) {
                $result = $this->engine->forShift($this->farm->id, $date, $shift, $milkType);

                expect($result->isOverAllocated())->toBeFalse(
                    "Seeded {$date} {$shift->value} {$milkType->value} is over-allocated."
                );

                /*
                 * Production + authorised adjustments must cover sales + usage. The
                 * engine's own remaining figure is that statement, so a negative
                 * remainder is the failure and a missing production row with
                 * allocations against it is the worse version of it.
                 */
                if (Quantity::isPositive($result->allocated)) {
                    expect($result->productionEntered)->toBeTrue(
                        "Seeded {$date} {$shift->value} {$milkType->value} allocates milk that was never recorded."
                    );
                }
            }
        }
    }
});

test('no sale was seeded against the deliberately unentered shift', function () {
    $yesterdayEvening = MilkSale::query()
        ->whereDate('sale_date', now()->subDay()->toDateString())
        ->where('shift', Shift::Evening->value)
        ->count();

    // The Phase 3 seed leaves that shift unentered on purpose, so that the
    // "Production not entered" state is visible. A sale there would both destroy
    // the example and allocate milk nobody recorded.
    expect($yesterdayEvening)->toBe(0);
});

test('no delivery was seeded for a customer paused on that date', function () {
    $pauses = app(CustomerPauseService::class);

    foreach (MilkSale::query()->active()->with('buyer')->get() as $sale) {
        expect($pauses->isPausedOn($sale->buyer, $sale->sale_date->toDateString()))->toBeFalse(
            "{$sale->buyer->name} has a seeded delivery on {$sale->sale_date->toDateString()} while paused."
        );
    }
});

test('no delivery was seeded for an archived or not-yet-started customer', function () {
    foreach (MilkSale::query()->active()->with('buyer')->get() as $sale) {
        expect((bool) $sale->buyer->is_active)->toBeTrue()
            ->and($sale->buyer->hasStartedBy($sale->sale_date->toDateString()))->toBeTrue();
    }
});

test('every seeded customer delivery is for a milk type that customer takes', function () {
    /*
     * Scoped to the customer grid. Milk preferences belong to a delivery round, not
     * to a Mandali collection or a vendor load, so applying this to every sale would
     * assert something that was never true of the other channels.
     */
    foreach (MilkSale::query()->active()
        ->fromSource(SaleSource::CustomerDailyGrid)
        ->with('buyer.preferences')->get() as $sale) {
        expect($sale->buyer->activePreferenceFor($sale->milk_type))->not->toBeNull(
            "{$sale->buyer->name} has a seeded {$sale->milk_type->value} delivery but no active preference for it."
        );
    }
});

/*
|--------------------------------------------------------------------------
| C. Reminders are not quantities, in the demo data too
|--------------------------------------------------------------------------
*/

test('no seeded quantity was copied from a reminder', function () {
    /*
     * Not a style point. A demo dataset where the delivered figure happens to equal
     * the reminder makes the single most important rule in the phase invisible, and
     * quietly teaches the next reader that the two are the same thing.
     */
    $matches = [];

    foreach (MilkSale::query()->active()->with('buyer.preferences')->get() as $sale) {
        $preference = $sale->buyer->activePreferenceFor($sale->milk_type);

        if ($preference === null) {
            continue;
        }

        $reminder = $sale->shift === Shift::Morning
            ? $preference->morning_reminder_qty
            : $preference->evening_reminder_qty;

        if (Quantity::compare($sale->quantity, $reminder) === 0) {
            $matches[] = "{$sale->buyer->name} {$sale->shift->value} {$sale->milk_type->value}";
        }
    }

    expect($matches)->toBe([], 'Seeded quantities equal to the reminder: '.implode(', ', $matches));
});

/*
|--------------------------------------------------------------------------
| D. The money adds up
|--------------------------------------------------------------------------
*/

test('every sale amount is its own quantity times its own snapshot', function () {
    foreach (MilkSale::query()->get() as $sale) {
        expectMoney(
            $sale->amount,
            $sale->expectedAmount(),
            "Sale {$sale->id} amount does not match quantity x unit_rate."
        );
    }
});

test('seeded resolver-priced sales use the rate the resolver returns', function () {
    /*
     * Scoped to the workflows that *resolve* a rate. A Mandali delivery and a generic
     * sale have their rate typed in — that is their normal workflow, not an override
     * — so comparing them against the resolver would be asserting a rule they were
     * deliberately built not to follow.
     */
    $resolver = app(PriceResolver::class);

    $sawOverride = false;
    $sawDefault = false;
    $checked = 0;

    $resolverPriced = MilkSale::query()->active()
        ->whereIn('source', [SaleSource::CustomerDailyGrid->value, SaleSource::VendorSale->value])
        ->with('buyer')
        ->get();

    foreach ($resolverPriced as $sale) {
        $price = $resolver->resolve($sale->buyer, $sale->milk_type, $sale->sale_date->toDateString());

        expect($price->found)->toBeTrue();
        expectMoney($sale->unit_rate, Quantity::money($price->rate()));

        $price->isFromBuyerOverride() ? $sawOverride = true : $sawDefault = true;
        $checked++;
    }

    // Both branches of the resolver are exercised by the demo data.
    expect($checked)->toBeGreaterThan(0)
        ->and($sawOverride)->toBeTrue()
        ->and($sawDefault)->toBeTrue();
});

test('seeded manual-rate sales are priced by hand, not by the resolver', function () {
    $manual = MilkSale::query()->active()
        ->whereIn('source', [SaleSource::MandaliDelivery->value, SaleSource::GenericSale->value])
        ->get();

    expect($manual)->not->toBeEmpty();

    foreach ($manual as $sale) {
        // No override provenance: there was nothing to depart from.
        expect($sale->hasRateOverride())->toBeFalse()
            ->and($sale->rate_override_reason)->toBeNull();

        // And the amount is still quantity times the typed rate.
        expectMoney($sale->amount, $sale->expectedAmount());
    }
});

test('seeded Mandali deliveries record fat and SNF, and nothing else does', function () {
    foreach (MilkSale::query()->active()->get() as $sale) {
        $sale->source === SaleSource::MandaliDelivery
            ? expect($sale->fat_percentage)->not->toBeNull('A Mandali delivery has no fat reading.')
            : expect($sale->hasQualityReadings())->toBeFalse(
                "A {$sale->source->value} sale carries a quality reading it should not."
            );
    }
});

test('every customer outstanding equals active sales minus active payments', function () {
    $checked = 0;

    foreach (Buyer::query()->directCustomers()->get() as $customer) {
        $sales = Quantity::money(
            MilkSale::query()->active()->where('buyer_id', $customer->id)->sum('amount')
        );
        $payments = Quantity::money(
            BuyerPayment::query()->active()->where('buyer_id', $customer->id)->sum('amount')
        );

        $breakdown = $this->outstanding->breakdownFor($customer);

        expectMoney($breakdown['sales'], $sales, "Sales total wrong for {$customer->name}.");
        expectMoney($breakdown['payments'], $payments, "Payments total wrong for {$customer->name}.");

        // Receivable adjustments are a named zero until Phase 5 creates the table.
        expectMoney($breakdown['adjustments'], '0.00');
        expectMoney($breakdown['outstanding'], bcsub($sales, $payments, 2), "Outstanding wrong for {$customer->name}.");

        $checked++;
    }

    expect($checked)->toBe(20);
});

test('no customer was seeded into a credit balance', function () {
    foreach (Buyer::query()->directCustomers()->get() as $customer) {
        expect(bccomp($this->outstanding->breakdownFor($customer)['outstanding'], '0.00', 2))
            ->toBeGreaterThanOrEqual(0, "{$customer->name} was seeded into a credit balance.");
    }
});

test('the demo data shows a settled customer and an unsettled one', function () {
    $balances = Buyer::query()->directCustomers()->get()
        ->map(fn (Buyer $c): string => $this->outstanding->breakdownFor($c)['outstanding']);

    expect($balances->contains(fn (string $b): bool => bccomp($b, '0.00', 2) > 0))->toBeTrue()
        ->and(BuyerPayment::query()->active()->count())->toBeGreaterThan(0);

    // At least one customer paid in full, so a zero balance is visible too.
    $paidInFull = Buyer::query()->directCustomers()->get()
        ->filter(fn (Buyer $c): bool => BuyerPayment::query()->active()->where('buyer_id', $c->id)->exists())
        ->filter(fn (Buyer $c): bool => bccomp($this->outstanding->breakdownFor($c)['outstanding'], '0.00', 2) === 0);

    expect($paidInFull)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| E. A receivable is not cash
|--------------------------------------------------------------------------
*/

test('each seeded payment credits its account exactly once', function () {
    $payments = BuyerPayment::query()->active()->get();

    expect($payments)->not->toBeEmpty();

    foreach ($payments as $payment) {
        $entries = FinancialLedgerEntry::query()
            ->where('reference_type', 'buyer_payment')
            ->where('reference_id', $payment->id)
            ->get();

        expect($entries)->toHaveCount(1, "Payment {$payment->id} has {$entries->count()} ledger entries.");

        $entry = $entries->first();

        expect($entry->financial_account_id)->toBe($payment->financial_account_id)
            ->and($entry->direction->value)->toBe('credit');

        expectMoney($entry->amount, Quantity::money($payment->amount));
    }
});

test('a milk sale posts nothing to the financial ledger', function () {
    // The customer owes money; none has moved. A sale that credited an account
    // would show cash the business does not have.
    expect(FinancialLedgerEntry::query()->where('reference_type', 'milk_sale')->count())->toBe(0);
});

test('each account balance includes its seeded customer receipts exactly once', function () {
    foreach (FinancialAccount::query()->get() as $account) {
        $receipts = Quantity::money(
            BuyerPayment::query()->active()->where('financial_account_id', $account->id)->sum('amount')
        );

        $credits = Quantity::money(
            FinancialLedgerEntry::query()
                ->where('financial_account_id', $account->id)
                ->where('reference_type', 'buyer_payment')
                ->where('direction', 'credit')
                ->sum('amount')
        );

        expectMoney($credits, $receipts, "Account {$account->name} credits do not match its receipts.");
    }
});

/*
|--------------------------------------------------------------------------
| F. Running it twice changes nothing
|--------------------------------------------------------------------------
*/

test('re-running the whole seed produces identical business results', function () {
    $snapshot = fn (): array => [
        'customers' => Buyer::query()->directCustomers()->count(),
        'preferences' => CustomerPreference::query()->count(),
        'pauses' => CustomerPause::query()->count(),
        'priceRules' => MilkPriceRule::query()->count(),
        'buyerPriceRules' => BuyerPriceRule::query()->count(),
        'production' => MilkProduction::query()->count(),
        'sales' => MilkSale::query()->count(),
        'activeSales' => MilkSale::query()->active()->count(),
        'litres' => Quantity::sum(MilkSale::query()->active()->pluck('quantity')),
        'saleAmount' => Quantity::money(MilkSale::query()->active()->sum('amount')),
        'payments' => BuyerPayment::query()->count(),
        'paymentAmount' => Quantity::money(BuyerPayment::query()->active()->sum('amount')),
        'ledger' => FinancialLedgerEntry::query()->count(),
        'ledgerAmount' => Quantity::money(FinancialLedgerEntry::query()->sum('amount')),
        'audits' => AuditLog::query()->count(),
    ];

    $before = $snapshot();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect($snapshot())->toBe($before);
});

test('the second run writes no duplicate sale, payment or ledger entry', function () {
    $this->seed(CustomerSalesSeeder::class);

    // The grid identity would refuse a duplicate sale outright; the assertion that
    // matters is that no *second* payment or ledger credit appeared, because
    // nothing in the database would have stopped one.
    $references = BuyerPayment::query()->pluck('reference')->filter();

    expect($references->count())->toBe($references->unique()->count());

    foreach (BuyerPayment::query()->get() as $payment) {
        expect(FinancialLedgerEntry::query()
            ->where('reference_type', 'buyer_payment')
            ->where('reference_id', $payment->id)
            ->count())->toBe(1);
    }
});

test('the seed is not guarded by an empty-table check', function () {
    /*
     * "Only seed if the table is empty" stops working the moment a later seeder
     * legitimately adds a row, and then silently seeds nothing for ever. The
     * customer seeders use stable identities instead — name, grid identity, payment
     * reference — so adding a customer by hand does not disable them.
     */
    $sources = [
        database_path('seeders/DirectCustomerSeeder.php'),
        database_path('seeders/CustomerSalesSeeder.php'),
        database_path('seeders/ChannelSaleSeeder.php'),
    ];

    foreach ($sources as $path) {
        $source = file_get_contents($path);

        expect($source)->not->toMatch('/count\(\)\s*===?\s*0/')
            ->and($source)->not->toContain('->doesntExist()');
    }

    // And a hand-added customer does not stop the seeder running.
    $before = MilkSale::query()->count();

    Buyer::factory()->directCustomer($this->business)->create(['name' => 'Hand Added Customer']);
    $this->seed(CustomerSalesSeeder::class);

    expect(MilkSale::query()->count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| G. Cancelled rows are not seeded
|--------------------------------------------------------------------------
*/

test('the seed creates no cancelled sale or payment', function () {
    // A fresh installation has no withdrawn transactions to explain.
    expect(MilkSale::query()->where('status', TransactionStatus::Cancelled->value)->count())->toBe(0)
        ->and(BuyerPayment::query()->where('status', TransactionStatus::Cancelled->value)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| H. What "idempotent" means about dates, precisely
|--------------------------------------------------------------------------
*/

test('every seeded business date sits in the four days before the seed ran', function () {
    /*
     * The seed is written in *offsets* — `now()->subDays(4)` through
     * `now()->subDay()` — not in literal dates, so a fresh installation always shows
     * the last few days rather than a window that recedes into the past. Phase 3
     * seeds the production, Phase 4 the customer deliveries and Phase 5 the channel
     * sales, all against the same four dates.
     *
     * The consequence, stated here because it is the part that surprises people: the
     * dates depend on the day the seeder ran, so they are stable for a given run and
     * not stable across runs on different days. See the next test for what that means
     * for re-seeding.
     */
    $from = now()->subDays(4)->startOfDay();
    $to = now()->subDay()->endOfDay();

    foreach (MilkProduction::query()->pluck('production_date') as $date) {
        expect($date->between($from, $to))->toBeTrue("Seeded production on {$date->toDateString()} is outside the window.");
    }

    foreach (MilkSale::query()->pluck('sale_date') as $date) {
        expect($date->between($from, $to))->toBeTrue("A seeded sale on {$date->toDateString()} is outside the window.");
    }

    foreach (BuyerPayment::query()->pluck('payment_date') as $date) {
        expect($date->between($from, $to))->toBeTrue("A seeded receipt on {$date->toDateString()} is outside the window.");
    }

    $settlement = BuyerSettlement::query()->firstOrFail();

    expect($settlement->period_start->toDateString())->toBe(now()->subDays(4)->toDateString())
        ->and($settlement->period_end->toDateString())->toBe(now()->subDay()->toDateString());
});

test('re-seeding the same day adds nothing, and that is what idempotent means here', function () {
    $before = [
        'sales' => MilkSale::query()->count(),
        'payments' => BuyerPayment::query()->count(),
        'settlements' => BuyerSettlement::query()->count(),
        'buyers' => Buyer::query()->count(),
    ];

    $this->seed(DatabaseSeeder::class);

    expect([
        'sales' => MilkSale::query()->count(),
        'payments' => BuyerPayment::query()->count(),
        'settlements' => BuyerSettlement::query()->count(),
        'buyers' => Buyer::query()->count(),
    ])->toBe($before);
});

test('re-seeding one day later does not collide with yesterday window', function () {
    /*
     * The case that actually broke. Every seeded date is an offset from the day the
     * seeder ran, so seeding on Tuesday asks for a settlement period one day later
     * than Monday's — which **overlaps** it, and the overlap rule refuses an
     * overlapping settlement. The rule is right; the seeder has to ask the same
     * question before calling the action, or a developer who seeds two days running
     * gets an exception instead of demo data.
     */
    $settlements = BuyerSettlement::query()->count();

    $this->travel(1)->days();

    $this->seed(DatabaseSeeder::class);

    // The existing draft covers the window the seeder would have asked for, so it is
    // left alone rather than duplicated or refused.
    expect(BuyerSettlement::query()->count())->toBe($settlements);

    // And the one day of new production is distributed without over-allocating.
    foreach (MilkSale::query()->pluck('sale_date')->map(fn ($date): string => $date->toDateString())->unique() as $date) {
        foreach (Shift::cases() as $shift) {
            foreach (MilkType::cases() as $milkType) {
                $result = $this->engine->forShift($this->farm->id, $date, $shift, $milkType);

                expect(Quantity::compare($result->remaining, '0.000'))->toBeGreaterThanOrEqual(
                    0,
                    "Re-seeded {$date} {$shift->value} {$milkType->value} is over-allocated."
                );
            }
        }
    }
});

test('a renamed demo buyer is renamed rather than duplicated', function () {
    /*
     * Demo buyers are keyed on their mobile number, because the display name is the
     * one thing about them likely to change — and keyed on the name, a rename created
     * a second buyer whose deliveries were then refused for milk the first one already
     * held.
     */
    $mandali = Buyer::query()->mandalis()->firstOrFail();
    $buyers = Buyer::query()->count();

    $mandali->forceFill(['name' => 'Renamed By Hand'])->save();

    $this->seed(DatabaseSeeder::class);

    expect(Buyer::query()->count())->toBe($buyers)
        ->and($mandali->refresh()->name)->not->toBe('Renamed By Hand');
});

test('re-seeding on a later day seeds that day window instead of duplicating this one', function () {
    $salesBefore = MilkSale::query()->count();
    $datesBefore = MilkSale::query()->pluck('sale_date')
        ->map(fn ($date): string => $date->toDateString())->unique()->sort()->values()->all();

    $this->travel(5)->days();

    $this->seed(DatabaseSeeder::class);

    $datesAfter = MilkSale::query()->pluck('sale_date')
        ->map(fn ($date): string => $date->toDateString())->unique()->sort()->values()->all();

    /*
     * Deliberate and worth being exact about. Idempotency is per *record*, matched on
     * the shape that identifies it — buyer, date, shift, milk type — so a second run a
     * week later does not duplicate the old rows; it adds the new window beside them.
     * A developer who seeds, works for a fortnight and seeds again gets more demo
     * data, not doubled demo data, and every day of it still reconciles.
     */
    expect(MilkSale::query()->count())->toBeGreaterThan($salesBefore)
        ->and($datesAfter)->not->toBe($datesBefore)
        // The original days survive untouched.
        ->and(array_intersect($datesBefore, $datesAfter))->toBe($datesBefore);

    // And the new window reconciles exactly as the first one did.
    foreach (array_diff($datesAfter, $datesBefore) as $date) {
        foreach (Shift::cases() as $shift) {
            foreach (MilkType::cases() as $milkType) {
                $result = $this->engine->forShift($this->farm->id, $date, $shift, $milkType);

                expect(Quantity::compare($result->remaining, '0.000'))->toBeGreaterThanOrEqual(
                    0,
                    "Re-seeded {$date} {$shift->value} {$milkType->value} is over-allocated."
                );
            }
        }
    }
});
