<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Services\BusinessContext;
use Illuminate\Database\Seeder;

/**
 * Development finance master data.
 *
 * Accounts are matched on name within the business so re-seeding never
 * duplicates them. Opening balances are zero: a real opening balance is a
 * business decision, and inventing one would make every derived balance in a
 * fresh installation wrong.
 */
class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        $business = app(BusinessContext::class)->business();

        $accounts = [
            ['Cash', FinancialAccountType::Cash],
            ['Main Bank Account', FinancialAccountType::Bank],
        ];

        foreach ($accounts as [$name, $type]) {
            FinancialAccount::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'name' => $name],
                [
                    'type' => $type->value,
                    'opening_balance' => '0.00',
                    'is_active' => true,
                ],
            );
        }

        /*
         * A few partners so split funding can be exercised in development. Real
         * installations add their own; these are matched by name so they are
         * never duplicated and are safe to rename or deactivate.
         */
        $partners = ['Partner A', 'Partner B', 'Partner C'];

        foreach ($partners as $index => $name) {
            Partner::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'name' => $name],
                [
                    'joining_date' => now()->subYears(2)->addMonths($index)->toDateString(),
                    'is_active' => true,
                ],
            );
        }

        $this->command?->info(sprintf(
            'Finance master data: %d accounts, %d partners.',
            FinancialAccount::query()->count(),
            Partner::query()->count(),
        ));
    }
}
