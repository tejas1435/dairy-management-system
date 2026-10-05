<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Farm;
use App\Models\MilkProduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MilkProduction> */
class MilkProductionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'production_date' => now()->toDateString(),
            'shift' => Shift::Morning->value,
            'cow_milk_quantity' => '10.000',
            'buffalo_milk_quantity' => '5.000',
            'notes' => null,
        ];
    }

    public function morning(): static
    {
        return $this->state(fn (): array => ['shift' => Shift::Morning->value]);
    }

    public function evening(): static
    {
        return $this->state(fn (): array => ['shift' => Shift::Evening->value]);
    }

    public function shift(Shift $shift): static
    {
        return $this->state(fn (): array => ['shift' => $shift->value]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['production_date' => $date]);
    }

    /** Explicit quantities, for tests where the numbers are the assertion. */
    public function quantities(string $cow, string $buffalo): static
    {
        return $this->state(fn (): array => [
            'cow_milk_quantity' => $cow,
            'buffalo_milk_quantity' => $buffalo,
        ]);
    }

    public function quantityFor(MilkType $milkType, string $quantity): static
    {
        return $this->state(fn (): array => [
            MilkProduction::columnFor($milkType) => $quantity,
        ]);
    }

    /**
     * A recorded shift that produced nothing.
     *
     * Distinct from having no row at all, which means production was never
     * entered. Tests that care about the difference use both.
     */
    public function recordedZero(): static
    {
        return $this->state(fn (): array => [
            'cow_milk_quantity' => '0.000',
            'buffalo_milk_quantity' => '0.000',
        ]);
    }
}
