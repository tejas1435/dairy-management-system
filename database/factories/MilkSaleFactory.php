<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\Farm;
use App\Models\MilkSale;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MilkSale> */
class MilkSaleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'buyer_id' => Buyer::factory(),
            'sales_channel_id' => null,
            'sale_date' => now()->toDateString(),
            'shift' => Shift::Morning->value,
            'milk_type' => MilkType::Cow->value,
            'quantity' => '1.000',
            'unit_rate' => '70.00',
            'amount' => '70.00',
            'source' => SaleSource::CustomerDailyGrid->value,
            'status' => TransactionStatus::Active->value,
        ];
    }

    /**
     * Attaches the buyer and takes its channel, so a factory-made sale is never
     * inconsistent with the buyer it belongs to.
     */
    public function forBuyer(Buyer $buyer): static
    {
        return $this->state(fn (): array => [
            'buyer_id' => $buyer->getKey(),
            'sales_channel_id' => $buyer->sales_channel_id,
        ]);
    }

    public function shift(Shift $shift): static
    {
        return $this->state(fn (): array => ['shift' => $shift->value]);
    }

    public function milkType(MilkType $milkType): static
    {
        return $this->state(fn (): array => ['milk_type' => $milkType->value]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['sale_date' => $date]);
    }

    /** Quantity and rate together, with the amount kept consistent. */
    public function priced(string $quantity, string $rate): static
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
            'unit_rate' => $rate,
            'amount' => Quantity::multiplyToMoney($quantity, $rate),
        ]);
    }

    public function source(SaleSource $source): static
    {
        return $this->state(fn (): array => ['source' => $source->value]);
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
