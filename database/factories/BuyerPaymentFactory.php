<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BuyerPayment> */
class BuyerPaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'payment_method_id' => null,
            'payment_date' => now()->toDateString(),
            'amount' => '500.00',
            'reference' => null,
            'notes' => null,
            'status' => TransactionStatus::Active->value,
        ];
    }

    public function amount(string $amount): static
    {
        return $this->state(fn (): array => ['amount' => $amount]);
    }

    public function into(FinancialAccount $account): static
    {
        return $this->state(fn (): array => ['financial_account_id' => $account->getKey()]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['payment_date' => $date]);
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
