<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SettlementStatus;
use App\Models\Buyer;
use App\Models\BuyerSettlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuyerSettlement>
 */
class BuyerSettlementFactory extends Factory
{
    protected $model = BuyerSettlement::class;

    /**
     * A draft covering one month, with nothing established.
     *
     * The snapshots are null rather than zero on purpose: a draft has agreed nothing,
     * and a factory that filled them in would let a test assert against figures no
     * finalization produced.
     */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'milk_quantity' => null,
            'expected_amount' => null,
            'statement_amount' => null,
            'difference' => null,
            'status' => SettlementStatus::Draft->value,
            'notes' => null,
        ];
    }

    public function forPeriod(string $start, string $end): static
    {
        return $this->state(fn (): array => ['period_start' => $start, 'period_end' => $end]);
    }

    public function withStatement(string $amount): static
    {
        return $this->state(fn (): array => ['statement_amount' => $amount]);
    }

    /**
     * A finalized settlement with explicit snapshots.
     *
     * For tests about what a finalized settlement *does* — blocking a sale
     * correction, accepting a payment — rather than about finalization itself, which
     * goes through the action.
     */
    public function finalized(string $expected = '1000.00', string $quantity = '100.000'): static
    {
        return $this->state(fn (): array => [
            'status' => SettlementStatus::Finalized->value,
            'milk_quantity' => $quantity,
            'expected_amount' => $expected,
            'finalized_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => SettlementStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Superseded',
        ]);
    }
}
