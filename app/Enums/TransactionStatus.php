<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a financial transaction record.
 *
 * Financial history is never deleted (MASTER_SPEC section 60). A cancelled
 * record stays in the database and in the audit trail, stops counting towards
 * active totals, and has its ledger effects reversed rather than erased.
 */
enum TransactionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('finance.statuses.'.$this->value);
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
