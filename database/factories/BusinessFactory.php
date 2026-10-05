<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DateFormat;
use App\Enums\Locale;
use App\Models\Business;
use App\Models\Farm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'legal_name' => null,
            'mobile' => $this->faker->numerify('9#########'),
            'email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->address(),
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'date_format' => DateFormat::default()->value,
            'default_locale' => Locale::default()->value,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** Creates the business together with its primary farm. */
    public function withPrimaryFarm(): static
    {
        return $this->afterCreating(function (Business $business): void {
            Farm::factory()->for($business)->primary()->create();
        });
    }
}
