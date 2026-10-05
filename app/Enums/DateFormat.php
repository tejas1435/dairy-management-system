<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Display formats offered for business dates. The specification requires
 * DD-MM-YYYY as the default; the others exist so the setting is meaningful
 * rather than a field with one possible value.
 */
enum DateFormat: string
{
    case DayMonthYearDashed = 'd-m-Y';
    case DayMonthYearSlashed = 'd/m/Y';
    case YearMonthDayDashed = 'Y-m-d';

    public static function default(): self
    {
        return self::DayMonthYearDashed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $format): string => $format->value, self::cases());
    }

    /** A human-readable example, e.g. "DD-MM-YYYY". */
    public function label(): string
    {
        return match ($this) {
            self::DayMonthYearDashed => 'DD-MM-YYYY',
            self::DayMonthYearSlashed => 'DD/MM/YYYY',
            self::YearMonthDayDashed => 'YYYY-MM-DD',
        };
    }
}
