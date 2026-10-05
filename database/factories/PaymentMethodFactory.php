<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => ucfirst($name),
            'code' => str($name)->slug('_')->toString(),
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 100,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
