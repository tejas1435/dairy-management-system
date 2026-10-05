<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\CustomerPause;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerPause> */
class CustomerPauseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => null,
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function between(string $start, ?string $end): static
    {
        return $this->state(fn (): array => [
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    /** Paused from a date with no stated end. */
    public function openEndedFrom(string $start): static
    {
        return $this->between($start, null);
    }

    public function because(string $reason): static
    {
        return $this->state(fn (): array => ['reason' => $reason]);
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
