<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\MilkAdjustment;
use App\Models\MilkProduction;
use App\Models\MilkUsage;
use App\Services\BusinessContext;
use Illuminate\Database\Seeder;

/**
 * Development milk data for the last few days.
 *
 * Internally consistent on purpose: every usage fits inside the production it is
 * recorded against, and the one adjustment is a plausible collection-point
 * difference rather than a number chosen to make a total come out even. A
 * developer opening the reconciliation screen should see a day that adds up, and
 * one shift left unentered so the "Production not entered" state is visible without
 * having to construct it.
 *
 * Idempotent: matched on farm, date and shift, which is the unique key, so
 * re-running changes nothing and never duplicates a row.
 *
 * Tests do not read any of this. They build their own data with factories, because
 * a test that depends on seed data breaks the day the seed data changes.
 */
class MilkProductionSeeder extends Seeder
{
    public function run(): void
    {
        $farmId = app(BusinessContext::class)->primaryFarm()->getKey();

        /*
         * Four days back to yesterday. Today is deliberately left alone: a fresh
         * installation should open on an empty day that the developer fills in
         * themselves.
         */
        $days = [
            ['offset' => 4, 'morning' => ['18.500', '9.250'], 'evening' => ['16.750', '8.500']],
            ['offset' => 3, 'morning' => ['19.000', '9.000'], 'evening' => ['17.250', '8.750']],
            ['offset' => 2, 'morning' => ['18.000', '8.500'], 'evening' => ['16.500', '8.000']],
            // Yesterday's evening is missing, so the "not entered" state is on screen.
            ['offset' => 1, 'morning' => ['18.750', '9.500'], 'evening' => null],
        ];

        foreach ($days as $day) {
            $date = now()->subDays($day['offset'])->toDateString();

            foreach ([Shift::Morning, Shift::Evening] as $shift) {
                $quantities = $shift === Shift::Morning ? $day['morning'] : $day['evening'];

                if ($quantities === null) {
                    continue;
                }

                MilkProduction::query()->firstOrCreate(
                    [
                        'farm_id' => $farmId,
                        'production_date' => $date,
                        'shift' => $shift->value,
                    ],
                    [
                        'cow_milk_quantity' => $quantities[0],
                        'buffalo_milk_quantity' => $quantities[1],
                    ],
                );
            }
        }

        $this->seedUsage($farmId);
        $this->seedAdjustment($farmId);

        $this->command?->info(sprintf(
            'Milk: %d production records, %d usages, %d adjustments.',
            MilkProduction::query()->count(),
            MilkUsage::query()->count(),
            MilkAdjustment::query()->count(),
        ));
    }

    /**
     * Calf feeding and a little wastage, comfortably inside what was produced.
     *
     * Matched on the whole shape of the row so re-seeding cannot add a second copy.
     */
    private function seedUsage(int $farmId): void
    {
        $date = now()->subDays(3)->toDateString();

        $rows = [
            [Shift::Morning, MilkType::Cow, MilkUsageType::CalfFeeding, '3.500'],
            [Shift::Morning, MilkType::Buffalo, MilkUsageType::HomeUse, '1.000'],
            [Shift::Evening, MilkType::Cow, MilkUsageType::Wastage, '0.250'],
        ];

        foreach ($rows as [$shift, $milkType, $usageType, $quantity]) {
            MilkUsage::query()->firstOrCreate(
                [
                    'farm_id' => $farmId,
                    'usage_date' => $date,
                    'shift' => $shift->value,
                    'milk_type' => $milkType->value,
                    'usage_type' => $usageType->value,
                ],
                [
                    'quantity' => $quantity,
                    'status' => TransactionStatus::Active->value,
                ],
            );
        }
    }

    /**
     * One authorised increase, of the kind that actually happens: the collection
     * point measured more than the shed recorded.
     */
    private function seedAdjustment(int $farmId): void
    {
        MilkAdjustment::query()->firstOrCreate(
            [
                'farm_id' => $farmId,
                'adjustment_date' => now()->subDays(2)->toDateString(),
                'shift' => Shift::Morning->value,
                'milk_type' => MilkType::Cow->value,
                'direction' => AdjustmentDirection::Increase->value,
            ],
            [
                'quantity' => '0.500',
                'reason' => 'Collection point measured 0.500 L more than the shed record',
                'status' => TransactionStatus::Active->value,
            ],
        );
    }
}
