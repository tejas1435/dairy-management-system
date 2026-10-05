<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilkType;
use App\Models\Business;
use App\Models\MilkPriceRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MilkPriceRule> */
class MilkPriceRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'milk_type' => MilkType::Cow->value,
            'rate' => '70.00',
            'effective_from' => now()->startOfYear()->toDateString(),
            'effective_to' => null,
        ];
    }

    public function forType(MilkType $type): static
    {
        return $this->state(fn (): array => ['milk_type' => $type->value]);
    }

    public function period(string $from, ?string $to = null): static
    {
        return $this->state(fn (): array => [
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }

    public function rate(string $rate): static
    {
        return $this->state(fn (): array => ['rate' => $rate]);
    }
}
