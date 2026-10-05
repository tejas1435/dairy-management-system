<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilkType;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BuyerPriceRule> */
class BuyerPriceRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'milk_type' => MilkType::Cow->value,
            'rate' => '72.00',
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
