<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Farm;
use App\Models\MilkAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MilkAdjustment> */
class MilkAdjustmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'adjustment_date' => now()->toDateString(),
            'shift' => Shift::Morning->value,
            'milk_type' => MilkType::Cow->value,
            'direction' => AdjustmentDirection::Increase->value,
            'quantity' => '1.000',
            // Never blank: the reason is the justification for the row existing.
            'reason' => 'Measured more than recorded at the collection point',
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function increase(string $quantity = '1.000'): static
    {
        return $this->state(fn (): array => [
            'direction' => AdjustmentDirection::Increase->value,
            'quantity' => $quantity,
        ]);
    }

    public function decrease(string $quantity = '1.000'): static
    {
        return $this->state(fn (): array => [
            'direction' => AdjustmentDirection::Decrease->value,
            'quantity' => $quantity,
        ]);
    }

    public function shift(Shift $shift): static
    {
        return $this->state(fn (): array => ['shift' => $shift->value]);
    }

    public function milkType(MilkType $milkType): static
    {
        return $this->state(fn (): array => ['milk_type' => $milkType->value]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['adjustment_date' => $date]);
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
