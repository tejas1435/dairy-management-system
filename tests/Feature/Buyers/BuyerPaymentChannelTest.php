<?php

use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\RecordChannelSale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\BuyerPriceRule;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Services\Buyers\BuyerOutstandingService;
use App\Support\BuyerPermissions;

/*
 * Receipts, per channel.
 *
 * One table, one action and one ceiling for every buyer — but the *permission* is the
 * buyer's own channel family, and the screens a user reaches are the ones that family
 * opens. This file is about those two things not leaking into each other, and about a
 * custom-channel buyer having somewhere to be paid at all.
 */

beforeEach(function (): void {
    $this->business = seedBusiness();
    seedAuthorization();
    seedPhase2Masters();

    $accounts = seedAccounts($this->business, cashOpening: '100000.00');
    $this->cash = $accounts['cash'];
    $this->method = PaymentMethod::query()->where('code', PaymentMethod::CASH)->firstOrFail();

    $this->farm = $this->business->primaryFarm();
    $this->date = '2026-10-10';

    seedDefaultPrices($this->business, cow: '70.00', buffalo: '85.00');
    seedProductionFor($this->farm, $this->date, cow: '1000.000', buffalo: '1000.000');

    $this->mandali = Buyer::factory()->mandali($this->business)->create(['name' => 'Shree Dairy Mandali']);
    $this->vendor = Buyer::factory()->vendor($this->business)->create(['name' => 'Patel Dairy']);
    $this->shop = Buyer::factory()->inCustomChannel($this->business)->create(['name' => 'Corner Sweet Shop']);

    // The vendor's own configured rate, so one 80.00 sale per channel is comparable
    // and none of them is an override needing a reason.
    BuyerPriceRule::factory()->for($this->vendor)->forType(MilkType::Cow)
        ->rate('80.00')->period('2026-10-01')->create();

    $this->outstanding = app(BuyerOutstandingService::class);

    $this->actingAs(superAdmin());

    /** A sale of 10.000 L at 80.00 = 800.00 to any buyer, through its own workflow. */
    $this->sell = function (Buyer $buyer, SaleSource $source) {
        return app(RecordChannelSale::class)->handle(
            buyer: $buyer,
            source: $source,
            date: $this->date,
            shift: Shift::Morning,
            milkType: MilkType::Cow,
            quantity: '10.000',
            rate: '80.00',
            attributes: $source === SaleSource::MandaliDelivery ? ['fat_percentage' => '4.50'] : [],
        );
    };

    $this->payForm = fn (Buyer $buyer, string $amount = '800.00'): array => [
        'amount' => $amount,
        'payment_date' => $this->date,
        'financial_account_id' => $this->cash->id,
        'payment_method_id' => $this->method->id,
    ];
});

/*
|--------------------------------------------------------------------------
| A. A custom-channel buyer has a trade profile
|--------------------------------------------------------------------------
*/

test('a custom-channel buyer has a list, a profile and a statement of its own', function () {
    ($this->sell)($this->shop, SaleSource::GenericSale);

    $this->get(route('other-buyers.index'))
        ->assertOk()
        ->assertSee('Corner Sweet Shop')
        ->assertSee('800.00');

    $this->get(route('other-buyers.show', $this->shop))
        ->assertOk()
        ->assertSee(__('buyers.ledger.title'))
        ->assertSee(__('buyers.payment.record'))
        ->assertSee('800.00');

    $this->get(route('other-buyers.statement', [$this->shop, 'month' => '2026-10']))
        ->assertOk()
        ->assertSee(__('buyers.statement.title'))
        ->assertSee('800.00');
});

test('the other-buyers list holds every custom channel and only those', function () {
    $hotel = SalesChannel::factory()->for($this->business)->create(['name' => 'Hotels', 'slug' => 'hotels']);
    $hotelBuyer = Buyer::factory()->inChannel($hotel)->create(['name' => 'Grand Hotel']);

    $customer = Buyer::factory()
        ->inChannel(SalesChannel::query()->where('slug', SalesChannel::DIRECT_CUSTOMER)->firstOrFail())
        ->create(['name' => 'Rajesh Patel']);

    $html = $this->get(route('other-buyers.index'))->assertOk()->getContent();

    // Two different custom channels, one list.
    expect($html)->toContain('Corner Sweet Shop')
        ->toContain($hotelBuyer->name)
        ->not->toContain($customer->name)
        ->not->toContain($this->mandali->name)
        ->not->toContain($this->vendor->name)
        // And the channel column, which is the only thing telling them apart.
        ->toContain($hotel->name)
        ->toContain($channelHeader = '<th scope="col">'.__('buyers.columns.channel').'</th>');

    /*
     * The single-channel lists do not draw it, asserted on the header cell rather
     * than on the word: the sidebar's "Sales Channels" settings link puts "Channel"
     * on every page, so a bare string check would pass for the wrong reason.
     */
    expect($this->get(route('mandalis.index'))->assertOk()->getContent())
        ->not->toContain($channelHeader);
});

test('a system-channel buyer is not reachable through the other-buyers screens', function () {
    foreach ([$this->mandali, $this->vendor] as $buyer) {
        $this->get(route('other-buyers.show', $buyer))->assertNotFound();
        $this->get(route('other-buyers.statement', $buyer))->assertNotFound();
    }

    // And a custom-channel buyer is not reachable through theirs.
    $this->get(route('mandalis.show', $this->shop))->assertNotFound();
    $this->get(route('vendors.show', $this->shop))->assertNotFound();
});

test('the other-buyers list says so when no custom channel exists', function () {
    $this->shop->forceDelete();

    $this->get(route('other-buyers.index'))
        ->assertOk()
        ->assertSee(__('buyers.other_buyer.empty'))
        // No create button, because there is no one channel to pre-select.
        ->assertDontSee(route('buyers.create', ['channel' => 1]));
});

/*
|--------------------------------------------------------------------------
| B. A receipt can be recorded and withdrawn from every channel's profile
|--------------------------------------------------------------------------
*/

test('a receipt can be recorded through the profile of every non-customer channel', function (string $which, string $source, string $routePrefix) {
    $buyer = $this->{$which};
    ($this->sell)($buyer, SaleSource::from($source));

    $this->post(route('buyers.payments.store', $buyer), ($this->payForm)($buyer))
        ->assertRedirect();

    expectMoney($this->outstanding->outstandingFor($buyer), '0.00');

    $payment = BuyerPayment::query()->where('buyer_id', $buyer->getKey())->firstOrFail();

    // And withdrawn again from the same screen.
    $this->put(route('buyers.payments.cancel', $payment), [
        'cancellation_reason' => 'Recorded against the wrong buyer',
    ])->assertRedirect();

    expect($payment->refresh()->status)->toBe(TransactionStatus::Cancelled);
    expectMoney($this->outstanding->outstandingFor($buyer), '800.00');

    // The profile the receipt was taken on shows it, withdrawn and all.
    $this->get(route($routePrefix.'.show', $buyer))->assertOk()->assertSee('800.00');
})->with([
    'mandali' => ['mandali', SaleSource::MandaliDelivery->value, 'mandalis'],
    'vendor' => ['vendor', SaleSource::VendorSale->value, 'vendors'],
    'custom channel' => ['shop', SaleSource::GenericSale->value, 'other-buyers'],
]);

/*
|--------------------------------------------------------------------------
| C. The permission families are independent
|--------------------------------------------------------------------------
*/

test('each channel resolves to its own payment permission', function () {
    expect(BuyerPermissions::familyFor($this->mandali).'.payment.create')->toBe('mandali.payment.create')
        ->and(BuyerPermissions::familyFor($this->vendor).'.payment.create')->toBe('vendor.payment.create')
        /*
         * A custom channel uses the customer family, per D26 — the same family its
         * view, create and update permissions already use. The alternative was a
         * permission per administrator-created channel, which cannot be seeded because
         * the channels do not exist yet, or borrowing the Mandali family, which would
         * hand settlement rights to whoever can be paid by a sweet shop.
         */
        ->and(BuyerPermissions::familyFor($this->shop).'.payment.create')->toBe('customer.payment.create');
});

test('one channel payment permission does not record a payment for another', function (string $permission, array $allowed) {
    foreach (['mandali' => $this->mandali, 'vendor' => $this->vendor, 'shop' => $this->shop] as $key => $buyer) {
        ($this->sell)($buyer, match ($key) {
            'mandali' => SaleSource::MandaliDelivery,
            'vendor' => SaleSource::VendorSale,
            default => SaleSource::GenericSale,
        });
    }

    $this->actingAs(userWithPermissions([$permission, 'customer.view', 'mandali.view', 'vendor.view']));

    foreach (['mandali' => $this->mandali, 'vendor' => $this->vendor, 'shop' => $this->shop] as $key => $buyer) {
        $response = $this->post(route('buyers.payments.store', $buyer), ($this->payForm)($buyer));

        in_array($key, $allowed, true)
            ? $response->assertRedirect()
            : $response->assertForbidden();
    }
})->with([
    'mandali.payment.create' => ['mandali.payment.create', ['mandali']],
    'vendor.payment.create' => ['vendor.payment.create', ['vendor']],
    // The custom channel shares the customer family, and only that one.
    'customer.payment.create' => ['customer.payment.create', ['shop']],
]);

test('withdrawing a receipt needs the cancel permission of that buyer own family', function () {
    ($this->sell)($this->vendor, SaleSource::VendorSale);

    $payment = app(RecordBuyerPayment::class)->handle($this->vendor, ($this->payForm)($this->vendor));

    // Recording is not withdrawing, and a Mandali's cancel right is not a vendor's.
    $this->actingAs(userWithPermissions(['vendor.view', 'vendor.payment.create', 'mandali.payment.cancel']));

    $this->put(route('buyers.payments.cancel', $payment), ['cancellation_reason' => 'Entered twice'])
        ->assertForbidden();

    $this->actingAs(userWithPermissions(['vendor.view', 'vendor.payment.cancel']));

    $this->put(route('buyers.payments.cancel', $payment), ['cancellation_reason' => 'Entered twice'])
        ->assertRedirect();

    expect($payment->refresh()->status)->toBe(TransactionStatus::Cancelled);
});

test('the other-buyers screens need the customer view permission and nothing wider', function () {
    $this->actingAs(userWithPermissions(['mandali.view', 'vendor.view']));
    $this->get(route('other-buyers.index'))->assertForbidden();
    $this->get(route('other-buyers.show', $this->shop))->assertForbidden();

    $this->actingAs(userWithPermissions(['customer.view']));
    $this->get(route('other-buyers.index'))->assertOk();
    $this->get(route('other-buyers.show', $this->shop))->assertOk();
    $this->get(route('other-buyers.statement', $this->shop))->assertOk();
});

test('the payment form is absent from a profile a user may only read', function () {
    ($this->sell)($this->shop, SaleSource::GenericSale);

    $this->actingAs(userWithPermissions(['customer.view']));

    $html = $this->get(route('other-buyers.show', $this->shop))->assertOk()->getContent();

    expect($html)->not->toContain(route('buyers.payments.store', $this->shop))
        ->toContain(__('buyers.ledger.title'));
});

/*
|--------------------------------------------------------------------------
| D. Navigation
|--------------------------------------------------------------------------
*/

test('the sidebar links to the other-buyers list for whoever may see it', function () {
    $html = $this->get(route('milk.production.index'))->assertOk()->getContent();
    expect($html)->toContain(route('other-buyers.index'));

    $this->actingAs(userWithPermissions(['milk.production.view', 'mandali.view', 'vendor.view']));

    $html = $this->get(route('milk.production.index'))->assertOk()->getContent();
    expect($html)->not->toContain(route('other-buyers.index'));
});

test('the other-buyer screens render in Gujarati and Hindi with no raw keys', function (string $locale) {
    ($this->sell)($this->shop, SaleSource::GenericSale);
    app(RecordBuyerPayment::class)->handle($this->shop, ($this->payForm)($this->shop, '100.00'));

    $user = superAdmin(['locale' => $locale]);

    foreach ([
        route('other-buyers.index'),
        route('other-buyers.show', $this->shop),
        route('other-buyers.statement', [$this->shop, 'month' => '2026-10']),
    ] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        expect($html)->not->toMatch('/\b(buyers|milk|nav|app|customers)\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/');
    }

    expect(__('buyers.other_buyer.title', [], $locale))->not->toBe(__('buyers.other_buyer.title', [], 'en'))
        ->and(__('nav.other_buyers', [], $locale))->not->toBe(__('nav.other_buyers', [], 'en'));
})->with(['gu', 'hi']);
