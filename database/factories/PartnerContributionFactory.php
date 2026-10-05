<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\PartnerContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PartnerContribution> */
class PartnerContributionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'payment_method_id' => null,
            'contribution_date' => now()->toDateString(),
            'amount' => '5000.00',
            'reference' => null,
            'notes' => null,
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function amount(string $amount): static
    {
        return $this->state(fn (): array => ['amount' => $amount]);
    }
}
