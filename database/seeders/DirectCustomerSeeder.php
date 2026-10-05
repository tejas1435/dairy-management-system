<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MilkType;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\CustomerPause;
use App\Models\CustomerPreference;
use App\Models\SalesChannel;
use App\Services\BusinessContext;
use Illuminate\Database\Seeder;

/**
 * Development direct customers.
 *
 * Twenty customers with real variety — cow only, buffalo only, both, different
 * reminders, four areas, three payment cycles, some with delivery notes, two with
 * price overrides, three with pauses — because a customer list where every row is
 * identical tells a developer nothing about whether the screen works.
 *
 * **No milk sales and no payments are seeded.** They would need production to exist
 * for the same dates and shifts, or they would over-allocate milk the farm never
 * recorded; and a seeded receivable is an invented financial history, which this
 * project has refused since Phase 2. The Pass 2 daily entry grid is where sales come
 * from, and it will enforce availability like everything else.
 *
 * Idempotent: customers are matched on name within the business, preferences on
 * customer and milk type, pauses on customer and start date. Re-running changes
 * nothing.
 *
 * Tests do not read any of this. They build their own data with factories.
 */
class DirectCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $business = app(BusinessContext::class)->business();

        $channel = SalesChannel::query()
            ->where('business_id', $business->getKey())
            ->where('slug', SalesChannel::DIRECT_CUSTOMER)
            ->first();

        if (! $channel) {
            $this->command?->warn('Direct customers skipped: the direct_customer sales channel is not seeded.');

            return;
        }

        foreach ($this->customers() as $row) {
            $customer = Buyer::query()->firstOrCreate(
                [
                    'business_id' => $business->getKey(),
                    'name' => $row['name'],
                ],
                [
                    'sales_channel_id' => $channel->getKey(),
                    'mobile' => $row['mobile'],
                    'area' => $row['area'],
                    'address' => $row['area'].', '.$business->name,
                    'payment_cycle' => $row['cycle'],
                    'delivery_note' => $row['note'] ?? null,
                    'start_date' => $row['start'] ?? null,
                    'is_active' => $row['active'] ?? true,
                ],
            );

            $this->seedPreferences($customer, $row['milk']);

            if (isset($row['pause'])) {
                $this->seedPause($customer, $row['pause'][0], $row['pause'][1], $row['pause'][2] ?? null);
            }

            if (isset($row['rate'])) {
                $this->seedPriceOverride($customer, $row['rate'][0], $row['rate'][1]);
            }
        }

        $this->command?->info(sprintf(
            'Direct customers: %d, preferences: %d, pauses: %d, price overrides: %d.',
            Buyer::query()->directCustomers()->count(),
            CustomerPreference::query()->count(),
            CustomerPause::query()->count(),
            BuyerPriceRule::query()->count(),
        ));
    }

    /**
     * Preferences for one customer.
     *
     * @param  array<string, array{0: string, 1: string}>  $milk  milk type => [morning, evening]
     */
    private function seedPreferences(Buyer $customer, array $milk): void
    {
        foreach ($milk as $type => [$morning, $evening]) {
            CustomerPreference::query()->firstOrCreate(
                [
                    'buyer_id' => $customer->getKey(),
                    'milk_type' => $type,
                ],
                [
                    'morning_reminder_qty' => $morning,
                    'evening_reminder_qty' => $evening,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * A customer-specific rate, open-ended from the start of the year.
     *
     * Two customers have one so the resolver's first branch is exercised by the demo
     * data and not only by tests — and so the daily grid shows a row priced away from
     * the business default. Dated from the start of the year, like the default rules,
     * so it covers every seeded delivery.
     */
    private function seedPriceOverride(Buyer $customer, string $milkType, string $rate): void
    {
        BuyerPriceRule::query()->firstOrCreate(
            [
                'buyer_id' => $customer->getKey(),
                'milk_type' => $milkType,
                'effective_from' => now()->startOfYear()->toDateString(),
            ],
            ['rate' => $rate, 'effective_to' => null],
        );
    }

    private function seedPause(Buyer $customer, string $start, ?string $end, ?string $reason): void
    {
        CustomerPause::query()->firstOrCreate(
            [
                'buyer_id' => $customer->getKey(),
                'start_date' => $start,
            ],
            [
                'end_date' => $end,
                'reason' => $reason,
                'status' => TransactionStatus::Active->value,
            ],
        );
    }

    /**
     * The demo round.
     *
     * Reminders vary deliberately, including customers with none: a reminder is
     * optional information, not a required field, and the screens have to look right
     * without it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customers(): array
    {
        $cow = MilkType::Cow->value;
        $buffalo = MilkType::Buffalo->value;

        $today = now();

        return [
            // Cow only, the commonest case.
            ['name' => 'Rajesh Patel', 'mobile' => '9820011001', 'area' => 'Station Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.000', '2.000']], 'note' => 'Leave at the gate, dog is friendly'],
            ['name' => 'Meena Shah', 'mobile' => '9820011002', 'area' => 'Station Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['0.500', '0.500']]],
            ['name' => 'Kiran Desai', 'mobile' => '9820011003', 'area' => 'Station Road', 'cycle' => 'weekly',
                'milk' => [$cow => ['1.500', '1.500']]],
            ['name' => 'Ashok Mehta', 'mobile' => '9820011004', 'area' => 'Market Lane', 'cycle' => 'monthly',
                'milk' => [$cow => ['2.000', '0.000']], 'note' => 'Morning only, nobody home in the evening'],
            ['name' => 'Sunita Joshi', 'mobile' => '9820011005', 'area' => 'Market Lane', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.000', '1.000']]],

            // Buffalo only.
            ['name' => 'Harish Trivedi', 'mobile' => '9820011006', 'area' => 'Market Lane', 'cycle' => 'monthly',
                'milk' => [$buffalo => ['1.000', '1.000']]],
            ['name' => 'Bhavna Solanki', 'mobile' => '9820011007', 'area' => 'Temple Street', 'cycle' => 'on collection',
                'milk' => [$buffalo => ['0.750', '0.750']]],
            // A long-standing customer on an agreed buffalo rate, so the resolver's
            // buyer-override branch is exercised by the demo data too.
            ['name' => 'Nilesh Chauhan', 'mobile' => '9820011008', 'area' => 'Temple Street', 'cycle' => 'monthly',
                'milk' => [$buffalo => ['2.000', '2.000']], 'note' => 'Ring the bell twice',
                'rate' => [$buffalo, '82.00']],

            // Both milk types.
            ['name' => 'Dinesh Rana', 'mobile' => '9820011009', 'area' => 'Temple Street', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.000', '1.000'], $buffalo => ['0.500', '0.500']]],
            ['name' => 'Anita Vyas', 'mobile' => '9820011010', 'area' => 'Temple Street', 'cycle' => 'weekly',
                'milk' => [$cow => ['0.500', '1.000'], $buffalo => ['1.000', '0.000']]],
            ['name' => 'Prakash Bhatt', 'mobile' => '9820011011', 'area' => 'Canal Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['2.000', '2.000'], $buffalo => ['1.000', '1.000']],
                'note' => 'Large household, two containers'],

            // No reminders at all: perfectly normal, and the screens must cope.
            ['name' => 'Jayesh Parmar', 'mobile' => '9820011012', 'area' => 'Canal Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['0.000', '0.000']]],
            ['name' => 'Rekha Modi', 'mobile' => '9820011013', 'area' => 'Canal Road', 'cycle' => 'monthly',
                'milk' => [$buffalo => ['0.000', '0.000']]],

            // Pauses: one running now, one upcoming, one open-ended.
            ['name' => 'Sanjay Thakkar', 'mobile' => '9820011014', 'area' => 'Station Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.000', '1.000']],
                'pause' => [
                    $today->copy()->subDays(2)->toDateString(),
                    $today->copy()->addDays(4)->toDateString(),
                    'Away for a family wedding',
                ]],
            ['name' => 'Falguni Pandya', 'mobile' => '9820011015', 'area' => 'Market Lane', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.500', '0.500']],
                'pause' => [
                    $today->copy()->addDays(10)->toDateString(),
                    $today->copy()->addDays(20)->toDateString(),
                    'Summer holiday',
                ]],
            ['name' => 'Mahesh Gohil', 'mobile' => '9820011016', 'area' => 'Canal Road', 'cycle' => 'on collection',
                'milk' => [$buffalo => ['1.000', '1.000']],
                'pause' => [$today->copy()->subDay()->toDateString(), null, null]],

            // A customer who has not started yet, so eligibility has something to
            // refuse.
            ['name' => 'Priya Nair', 'mobile' => '9820011017', 'area' => 'Station Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.000', '1.000']],
                'start' => $today->copy()->addDays(7)->toDateString()],

            // An archived customer, so the list filter has something to show.
            ['name' => 'Vikram Oza', 'mobile' => '9820011018', 'area' => 'Market Lane', 'cycle' => 'monthly',
                'milk' => [$cow => ['1.000', '1.000']], 'active' => false],

            // Started partway through, which is the normal case for a new customer.
            ['name' => 'Krishna Bhatia', 'mobile' => '9820011019', 'area' => 'Temple Street', 'cycle' => 'weekly',
                'milk' => [$cow => ['0.500', '0.500'], $buffalo => ['0.500', '0.500']],
                'start' => $today->copy()->subDays(20)->toDateString()],
            // Bulk buyer on a negotiated cow rate.
            ['name' => 'Hetal Dave', 'mobile' => '9820011020', 'area' => 'Canal Road', 'cycle' => 'monthly',
                'milk' => [$cow => ['3.000', '3.000']],
                'note' => 'Hotel kitchen — deliver before 6am',
                'rate' => [$cow, '68.00']],
        ];
    }
}
