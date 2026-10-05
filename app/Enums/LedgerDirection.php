<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Direction of a financial ledger entry, from the business account's point of
 * view: a credit increases the account, a debit decreases it.
 */
enum LedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('finance.ledger.directions.'.$this->value);
    }

    /** The direction that cancels this one out. */
    public function opposite(): self
    {
        return $this === self::Credit ? self::Debit : self::Credit;
    }

    /** +1 for a credit, -1 for a debit, for balance arithmetic. */
    public function sign(): int
    {
        return $this === self::Credit ? 1 : -1;
    }
}
