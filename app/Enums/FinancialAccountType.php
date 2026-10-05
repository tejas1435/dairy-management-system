<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bookkeeping account types. These are accounts inside this application; the
 * system never connects to a bank (MASTER_SPEC section 28).
 */
enum FinancialAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('finance.accounts.types.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'cash-stack',
            self::Bank => 'bank',
            self::Other => 'wallet2',
        };
    }
}
