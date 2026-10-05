<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'farm_id' => null,
            'expense_category_id' => ExpenseCategory::factory(),
            'expense_date' => now()->toDateString(),
            'amount' => '1000.00',
            'description' => $this->faker->sentence(3),
            'payee_name' => null,
            'notes' => null,
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function amount(string $amount): static
    {
        return $this->state(fn (): array => ['amount' => $amount]);
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
