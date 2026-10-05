<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\BuyerPriceRule;
use App\Models\BuyerSettlement;
use App\Models\FinancialAccount;
use App\Models\MilkSale;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Services\BusinessContext;
use App\Services\Buyers\BuyerOutstandingService;
use App\Support\Quantity;
use Illuminate\Database\Seeder;

/**
 * Development Mandali, vendor and other-channel buyers, with a few sales.
 *
 * Written through the **real domain actions**, like the Phase 4 customer seeder: a
 * Mandali delivery goes through {@see RecordChannelSale}, so availability, the farm,
 * the amount and the audit record all apply. If the quantities below ever stopped
 * fitting inside the seeded production, `MilkAvailability` would refuse them and the
 * seed would fail loudly rather than quietly producing a farm that distributed milk
 * it never had.
 *
 * ## The quantities are deliberately small
 *
 * Phase 3 seeds four days of production and Phase 4 already allocates part of it to
 * direct customers. These figures sit comfortably inside what is left on the same
 * dates, with headroom, so a developer can add their own entries on the demo data
 * without immediately hitting the ceiling.
 *
 * ## The settlement is a draft on purpose
 *
 * A finalized settlement would be more impressive and worse. It would post a
 * receivable adjustment nobody asked for, and it would **close its period** — so a
 * developer trying to correct a seeded delivery would be refused until they cancelled
 * a settlement they did not create. A draft shows the screen, carries no accounting
 * effect, and leaves finalizing as the one click that demonstrates the interesting
 * part.
 *
 * Its statement amount is set slightly above the system figure so that finalizing it
 * produces a visible difference and a real adjustment.
 *
 * Idempotent: sales are matched on their own shape, payments on a stable reference,
 * and the settlement on its buyer and period.
 */
class ChannelSaleSeeder extends Seeder
{
    public const PAYMENT_REFERENCE_PREFIX = 'SEED-TRADE-';

    public function run(): void
    {
        $business = app(BusinessContext::class)->business();
        $farmId = app(BusinessContext::class)->primaryFarmId();

        $channels = SalesChannel::query()
            ->where('business_id', $business->getKey())
            ->pluck('id', 'slug');

        if (! $channels->has(SalesChannel::MANDALI) || ! $channels->has(SalesChannel::VENDOR)) {
            $this->command?->warn('Channel sales skipped: the system sales channels are not seeded.');

            return;
        }

        $buyers = $this->seedBuyers($business->getKey(), $channels);
        $sales = $this->seedSales($buyers, $farmId);
        $extras = $this->seedSettlementAndPayments($buyers);

        $this->command?->info(sprintf(
            'Trade buyers: %d mandali, %d vendor, %d custom. Channel sales: %d active [%s]. %s',
            Buyer::query()->mandalis()->count(),
            Buyer::query()->vendors()->count(),
            Buyer::query()->customChannel()->count(),
            MilkSale::query()->active()->whereIn('source', [
                SaleSource::MandaliDelivery->value,
                SaleSource::VendorSale->value,
                SaleSource::GenericSale->value,
            ])->count(),
            $sales,
            $extras,
        ));
    }

    /**
     * One Mandali, two vendors and one custom-channel buyer.
     *
     * The custom channel is created here because, by definition, a custom channel has
     * no seeder of its own — and without one the generic sale form has nothing to
     * show, which makes it look broken rather than empty.
     *
     * @return array<string, Buyer>
     */
    private function seedBuyers(int $businessId, $channels): array
    {
        $sweetShop = SalesChannel::query()->firstOrCreate(
            ['business_id' => $businessId, 'slug' => 'sweet_shop'],
            ['name' => 'Sweet Shops', 'is_system' => false, 'is_active' => true, 'sort_order' => 40],
        );

        $rows = [
            'mandali' => [
                'name' => 'Shree Sagar Mandali',
                'channel' => $channels[SalesChannel::MANDALI],
                'mobile' => '9820022001',
                'area' => 'Collection Centre',
                'cycle' => 'monthly',
                'notes' => 'Collects both shifts; settles monthly against its own statement.',
            ],
            'vendor_a' => [
                'name' => 'Patel Dairy',
                'channel' => $channels[SalesChannel::VENDOR],
                'mobile' => '9820022002',
                'area' => 'Station Road',
                'cycle' => 'weekly',
                // A standing agreed rate, so the resolver's buyer branch is exercised.
                'rate' => [MilkType::Cow->value, '74.00'],
            ],
            'vendor_b' => [
                'name' => 'Krishna Milk Traders',
                'channel' => $channels[SalesChannel::VENDOR],
                'mobile' => '9820022003',
                'area' => 'Market Lane',
                'cycle' => 'monthly',
            ],
            'shop' => [
                'name' => 'Gokul Sweets',
                'channel' => $sweetShop->getKey(),
                'mobile' => '9820022004',
                'area' => 'Market Lane',
                'cycle' => 'weekly',
                'notes' => 'Buys buffalo milk for mithai; rate agreed per order.',
            ],
        ];

        $buyers = [];

        foreach ($rows as $key => $row) {
            /*
             * Keyed on the **mobile number**, not the name.
             *
             * A demo buyer's display name is the one thing about it likely to change —
             * it did, when `Shree Amul Mandali` was renamed because shipping a real
             * dairy's brand in seeded data is not demo data. Keyed on the name, that
             * rename created a *second* Mandali on the next seed, and its deliveries
             * were then refused because the first one already held that shift's milk.
             *
             * `updateOrCreate` so the rename lands on the row the seeder already owns
             * rather than beside it.
             */
            $buyer = Buyer::query()->updateOrCreate(
                ['business_id' => $businessId, 'mobile' => $row['mobile']],
                [
                    'name' => $row['name'],
                    'sales_channel_id' => $row['channel'],
                    'area' => $row['area'],
                    'payment_cycle' => $row['cycle'],
                    'notes' => $row['notes'] ?? null,
                    'is_active' => true,
                ],
            );

            if (isset($row['rate'])) {
                BuyerPriceRule::query()->firstOrCreate(
                    [
                        'buyer_id' => $buyer->getKey(),
                        'milk_type' => $row['rate'][0],
                        'effective_from' => now()->startOfYear()->toDateString(),
                    ],
                    ['rate' => $row['rate'][1], 'effective_to' => null],
                );
            }

            $buyers[$key] = $buyer;
        }

        return $buyers;
    }

    /**
     * A handful of sales across the three workflows.
     *
     * @param  array<string, Buyer>  $buyers
     */
    private function seedSales(array $buyers, int $farmId): string
    {
        $record = app(RecordChannelSale::class);
        $written = 0;

        foreach ($this->salesPlan() as $row) {
            $buyer = $buyers[$row['buyer']] ?? null;

            if ($buyer === null) {
                continue;
            }

            $source = $row['source'];
            $date = now()->subDays($row['offset'])->toDateString();
            $shift = $row['shift'];
            $milkType = $row['milk_type'];

            /*
             * Matched on the whole shape of the sale rather than a generated key:
             * unlike the customer grid, these sources are deliberately not held to
             * one row per shift (two collection trips in a morning are real), so the
             * seeder has to recognise its own work itself.
             */
            $exists = MilkSale::query()
                ->fromSource($source)
                ->where('farm_id', $farmId)
                ->where('buyer_id', $buyer->getKey())
                ->whereDate('sale_date', $date)
                ->where('shift', $shift->value)
                ->where('milk_type', $milkType->value)
                ->exists();

            if ($exists) {
                continue;
            }

            $record->handle(
                buyer: $buyer,
                source: $source,
                date: $date,
                shift: $shift,
                milkType: $milkType,
                quantity: $row['quantity'],
                // Null for a vendor: take the configured rate rather than override it.
                rate: $row['rate'] ?? null,
                attributes: $row['attributes'] ?? [],
            );

            $written++;
        }

        return sprintf('%d written this run', $written);
    }

    /**
     * The demo sales.
     *
     * Dates are the two days Phase 3 recorded both shifts for, which is also where
     * the Phase 4 customer deliveries sit — so these figures are checked against what
     * those left behind, with headroom to spare.
     *
     * @return array<int, array<string, mixed>>
     */
    private function salesPlan(): array
    {
        return [
            // Mandali: the rate is typed in, with fat and SNF recorded beside it.
            [
                'buyer' => 'mandali', 'source' => SaleSource::MandaliDelivery,
                'offset' => 4, 'shift' => Shift::Morning, 'milk_type' => MilkType::Cow,
                'quantity' => '6.000', 'rate' => '72.00',
                'attributes' => ['fat_percentage' => '4.60', 'snf_percentage' => '8.60'],
            ],
            [
                'buyer' => 'mandali', 'source' => SaleSource::MandaliDelivery,
                'offset' => 3, 'shift' => Shift::Morning, 'milk_type' => MilkType::Cow,
                'quantity' => '4.000', 'rate' => '72.00',
                'attributes' => ['fat_percentage' => '4.40', 'snf_percentage' => '8.50'],
            ],

            // Vendor on a standing agreed rate: no rate passed, so it resolves.
            [
                'buyer' => 'vendor_a', 'source' => SaleSource::VendorSale,
                'offset' => 4, 'shift' => Shift::Evening, 'milk_type' => MilkType::Cow,
                'quantity' => '5.000',
            ],

            // Vendor on the business default.
            [
                'buyer' => 'vendor_b', 'source' => SaleSource::VendorSale,
                'offset' => 4, 'shift' => Shift::Morning, 'milk_type' => MilkType::Buffalo,
                'quantity' => '3.000',
            ],

            // A custom channel: the rate is typed in, because it has no price rules.
            [
                'buyer' => 'shop', 'source' => SaleSource::GenericSale,
                'offset' => 4, 'shift' => Shift::Evening, 'milk_type' => MilkType::Buffalo,
                'quantity' => '2.000', 'rate' => '95.00',
            ],
        ];
    }

    /**
     * A draft settlement and two receipts.
     *
     * See the class docblock for why the settlement is left as a draft. The receipts
     * are on account rather than against a settlement, since there is no finalized
     * settlement to pay — which is itself the common case for a vendor.
     *
     * @param  array<string, Buyer>  $buyers
     */
    private function seedSettlementAndPayments(array $buyers): string
    {
        $cash = FinancialAccount::query()->where('name', 'Cash')->first();
        $bank = FinancialAccount::query()->where('name', 'Main Bank Account')->first();
        $cashMethod = PaymentMethod::query()->where('code', PaymentMethod::CASH)->first();
        $transfer = PaymentMethod::query()->where('code', PaymentMethod::BANK_TRANSFER)->first();

        if (! $cash || ! $bank || ! $cashMethod || ! $transfer) {
            return 'Settlement and payments skipped: accounts or methods are not seeded.';
        }

        $written = 0;
        $mandali = $buyers['mandali'] ?? null;

        if ($mandali !== null) {
            $start = now()->subDays(4)->toDateString();
            $end = now()->subDay()->toDateString();

            /*
             * Any **overlapping** active settlement counts as "already seeded", not
             * just one starting on the same day.
             *
             * The seeded dates are offsets from the day the seeder runs, so a
             * developer who seeded on Monday and seeds again on Tuesday asks for a
             * window one day later — which overlaps Monday's. Matching on
             * `period_start` alone, the seeder would call the domain action, the
             * overlap rule would refuse it, and the whole seed would die on a demo
             * record. The rule is right; the seeder has to ask the same question it
             * does.
             */
            $exists = BuyerSettlement::query()
                ->where('buyer_id', $mandali->getKey())
                ->active()
                ->overlapping($start, $end)
                ->exists();

            if (! $exists) {
                /*
                 * The statement is set a little above the system figure — 10 L at
                 * 72.00 is 720.00 — so finalizing produces a visible difference and a
                 * real receivable adjustment, which is the part worth seeing.
                 */
                app(CreateBuyerSettlement::class)->handle(
                    mandali: $mandali,
                    periodStart: $start,
                    periodEnd: $end,
                    statementAmount: '745.00',
                    notes: 'Statement received by post. Finalize to record the difference.',
                );

                $written++;
            }

            $written += $this->recordPayment($mandali, 'mandali-1', '500.00', $cash->getKey(), $cashMethod->getKey());
        }

        if (isset($buyers['vendor_a'])) {
            $written += $this->recordPayment(
                $buyers['vendor_a'], 'vendor-1', '200.00', $bank->getKey(), $transfer->getKey()
            );
        }

        return sprintf('Settlement and payments: %d written this run.', $written);
    }

    /** Records one demo receipt unless its reference is already on the books. */
    private function recordPayment(Buyer $buyer, string $key, string $amount, int $accountId, int $methodId): int
    {
        $reference = self::PAYMENT_REFERENCE_PREFIX.$key;

        if (BuyerPayment::query()->where('reference', $reference)->exists()) {
            return 0;
        }

        /*
         * Capped at what the buyer actually owes, so the seed cannot trip the
         * overpayment refusal if the sales above are ever reduced. A payment larger
         * than the balance is refused by the action, which is the behaviour under
         * test elsewhere — a seeder is not the place to discover it.
         */
        $outstanding = app(BuyerOutstandingService::class)->outstandingFor($buyer);

        if (bccomp($outstanding, '0.00', Quantity::MONEY_SCALE) <= 0) {
            return 0;
        }

        $payable = bccomp($amount, $outstanding, Quantity::MONEY_SCALE) > 0 ? $outstanding : $amount;

        app(RecordBuyerPayment::class)->handle($buyer, [
            'payment_date' => now()->subDay()->toDateString(),
            'amount' => $payable,
            'financial_account_id' => $accountId,
            'payment_method_id' => $methodId,
            'reference' => $reference,
            'notes' => 'Development demo receipt',
        ]);

        return 1;
    }
}
