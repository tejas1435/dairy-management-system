<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two milking shifts of a day (MASTER_SPEC section 14).
 *
 * Every operational milk record is keyed by shift: production, internal usage,
 * adjustments, reconciliation, and the sales modules that arrive in Phases 4 and
 * 5. This enum is the only place the two values are spelled, so a third shift
 * would be a code and data change rather than a search through Blade templates.
 *
 * Persisted as a string, not a MySQL ENUM, for the same reason.
 */
enum Shift: string
{
    case Morning = 'morning';
    case Evening = 'evening';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('milk.shifts.'.$this->value);
    }

    /** Bootstrap icon suggesting the time of day. */
    public function icon(): string
    {
        return $this === self::Morning ? 'sunrise' : 'sunset';
    }
}
