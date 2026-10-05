<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilkType;
use App\Models\Buyer;
use App\Models\CustomerPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerPreference> */
class CustomerPreferenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'milk_type' => MilkType::Cow->value,
            'morning_reminder_qty' => '1.000',
            'evening_reminder_qty' => '1.000',
            'is_active' => true,
        ];
    }

    public function milkType(MilkType $milkType): static
    {
        return $this->state(fn (): array => ['milk_type' => $milkType->value]);
    }

    public function cow(): static
    {
        return $this->milkType(MilkType::Cow);
    }

    public function buffalo(): static
    {
        return $this->milkType(MilkType::Buffalo);
    }

    /** Explicit reminders, for tests where the figures are the assertion. */
    public function reminders(string $morning, string $evening): static
    {
        return $this->state(fn (): array => [
            'morning_reminder_qty' => $morning,
            'evening_reminder_qty' => $evening,
        ]);
    }

    /** A preference with no reminders at all, which is perfectly normal. */
    public function withoutReminders(): static
    {
        return $this->reminders('0.000', '0.000');
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
