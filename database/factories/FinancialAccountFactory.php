<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FinancialAccountType;
use App\Models\Business;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialAccount> */
class FinancialAccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->unique()->company().' Account',
            'type' => FinancialAccountType::Cash->value,
            'opening_balance' => '0.00',
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (): array => ['type' => FinancialAccountType::Cash->value]);
    }

    public function bank(): static
    {
        return $this->state(fn (): array => ['type' => FinancialAccountType::Bank->value]);
    }

    public function withOpeningBalance(string $amount): static
    {
        return $this->state(fn (): array => ['opening_balance' => $amount]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
