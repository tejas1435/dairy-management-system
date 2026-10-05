<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ways milk leaves the farm without being sold (MASTER_SPEC section 15).
 *
 * These are allocations, not sales: the milk is gone either way, so
 * reconciliation counts them alongside sales, but no money and no buyer is
 * involved. Recording a calf feeding as a zero-rate sale would put a phantom
 * buyer in the ledger and the reports.
 */
enum MilkUsageType: string
{
    case CalfFeeding = 'calf_feeding';
    case HomeUse = 'home_use';
    case Sample = 'sample';
    case Wastage = 'wastage';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('milk.usage_types.'.$this->value);
    }

    /**
     * Wastage is the one type worth drawing the eye to on a list: the others are
     * milk put to use, this one is milk lost.
     */
    public function badge(): string
    {
        return $this === self::Wastage
            ? 'text-danger-emphasis bg-danger-subtle border border-danger-subtle'
            : 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle';
    }
}
