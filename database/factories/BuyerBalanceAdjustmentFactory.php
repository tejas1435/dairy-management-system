<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BalanceAdjustmentDirection;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuyerBalanceAdjustment>
 */
class BuyerBalanceAdjustmentFactory extends Factory
{
    protected $model = BuyerBalanceAdjustment::class;

    /** Always a positive amount: the direction carries the sign. */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'buyer_settlement_id' => null,
            'adjustment_date' => '2026-09-30',
            'direction' => BalanceAdjustmentDirection::Increase->value,
            'amount' => '500.00',
            'reason' => 'Settlement difference',
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function increase(string $amount = '500.00'): static
    {
        return $this->state(fn (): array => [
            'direction' => BalanceAdjustmentDirection::Increase->value,
            'amount' => $amount,
        ]);
    }

    public function decrease(string $amount = '300.00'): static
    {
        return $this->state(fn (): array => [
            'direction' => BalanceAdjustmentDirection::Decrease->value,
            'amount' => $amount,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Entered against the wrong buyer',
        ]);
    }
}
