<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Farm;
use App\Models\MilkUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MilkUsage> */
class MilkUsageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'usage_date' => now()->toDateString(),
            'shift' => Shift::Morning->value,
            'milk_type' => MilkType::Cow->value,
            'usage_type' => MilkUsageType::CalfFeeding->value,
            'quantity' => '2.000',
            'notes' => null,
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function shift(Shift $shift): static
    {
        return $this->state(fn (): array => ['shift' => $shift->value]);
    }

    public function milkType(MilkType $milkType): static
    {
        return $this->state(fn (): array => ['milk_type' => $milkType->value]);
    }

    public function usageType(MilkUsageType $usageType): static
    {
        return $this->state(fn (): array => ['usage_type' => $usageType->value]);
    }

    public function quantity(string $quantity): static
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['usage_date' => $date]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Test cancellation',
        ]);
    }
}
