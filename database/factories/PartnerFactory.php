<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Partner> */
class PartnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->name(),
            'mobile' => $this->faker->numerify('9#########'),
            'email' => $this->faker->unique()->safeEmail(),
            'joining_date' => now()->subYears(2)->toDateString(),
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
