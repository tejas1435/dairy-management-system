<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\SalesChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesChannel> */
class SalesChannelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'business_id' => Business::factory(),
            'name' => ucfirst($name),
            'slug' => str($name)->slug('_')->toString(),
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 100,
        ];
    }

    public function system(string $slug, string $name): static
    {
        return $this->state(fn (): array => [
            'slug' => $slug,
            'name' => $name,
            'is_system' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
