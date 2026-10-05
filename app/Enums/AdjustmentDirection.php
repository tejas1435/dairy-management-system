<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an authorised milk adjustment adds milk to the available pool or takes
 * milk out of it.
 *
 * The direction is explicit and the quantity is always positive. The alternative
 * -- one signed quantity column -- reads badly in every place it surfaces: a
 * form asking for "-2.500 litres", a list showing a negative litre count, and a
 * validation rule that has to permit negative milk in exactly one table while
 * forbidding it everywhere else. See docs/DECISIONS.md D31.
 */
enum AdjustmentDirection: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('milk.adjustment_directions.'.$this->value);
    }

    /** +1 when the adjustment adds milk, -1 when it removes it. */
    public function sign(): int
    {
        return $this === self::Increase ? 1 : -1;
    }

    public function badge(): string
    {
        return $this === self::Increase
            ? 'text-success-emphasis bg-success-subtle border border-success-subtle'
            : 'text-warning-emphasis bg-warning-subtle border border-warning-subtle';
    }

    public function icon(): string
    {
        return $this === self::Increase ? 'arrow-up-circle' : 'arrow-down-circle';
    }
}
