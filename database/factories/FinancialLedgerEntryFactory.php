<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LedgerDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FinancialLedgerEntry> */
class FinancialLedgerEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'financial_account_id' => FinancialAccount::factory(),
            'entry_date' => now()->toDateString(),
            'direction' => LedgerDirection::Credit->value,
            'amount' => '100.00',
            'reference_type' => null,
            'reference_id' => null,
            'description' => $this->faker->sentence(3),
            'idempotency_key' => 'test:'.Str::uuid()->toString(),
        ];
    }

    public function credit(string $amount): static
    {
        return $this->state(fn (): array => [
            'direction' => LedgerDirection::Credit->value,
            'amount' => $amount,
        ]);
    }

    public function debit(string $amount): static
    {
        return $this->state(fn (): array => [
            'direction' => LedgerDirection::Debit->value,
            'amount' => $amount,
        ]);
    }
}
