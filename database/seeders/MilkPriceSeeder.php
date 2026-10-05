<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MilkType;
use App\Models\MilkPriceRule;
use App\Services\BusinessContext;
use Illuminate\Database\Seeder;

/**
 * Seeds an opening business default price for each milk type.
 *
 * Only ever creates the first, open-ended period, and only when none exists.
 * Re-seeding must not append a second period or disturb history an
 * administrator has since built up through Settings.
 */
class MilkPriceSeeder extends Seeder
{
    public function run(): void
    {
        $business = app(BusinessContext::class)->business();

        $defaults = [
            [MilkType::Cow, '70.00'],
            [MilkType::Buffalo, '85.00'],
        ];

        foreach ($defaults as [$type, $rate]) {
            $exists = MilkPriceRule::query()
                ->where('business_id', $business->getKey())
                ->where('milk_type', $type->value)
                ->exists();

            if ($exists) {
                continue;
            }

            MilkPriceRule::create([
                'business_id' => $business->getKey(),
                'milk_type' => $type->value,
                'rate' => $rate,
                'effective_from' => now()->startOfYear()->toDateString(),
                'effective_to' => null,
            ]);
        }

        $this->command?->info('Milk price rules: '.MilkPriceRule::query()->count().'.');
    }
}
