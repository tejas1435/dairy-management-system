<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FundingAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FundingAllocation> */
class FundingAllocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'payable_type' => 'expense',
            'payable_id' => Expense::factory(),
            'source_type' => 'financial_account',
            'source_id' => FinancialAccount::factory(),
            'amount' => '1000.00',
            'payment_method_id' => null,
            'reference' => null,
        ];
    }
}
