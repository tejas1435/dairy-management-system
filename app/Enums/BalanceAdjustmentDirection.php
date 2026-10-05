<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a receivable adjustment raises what a buyer owes or lowers it.
 *
 * Same shape as {@see AdjustmentDirection}, and deliberately not the same enum.
 * That one is about litres — its labels come from the `milk.*` translations and its
 * sign feeds the reconciliation formula. Sharing it would mean a milk direction
 * quietly acquiring money semantics, and a form asking about "milk adjustment
 * direction" when the subject is a rupee difference on a Mandali statement.
 * Two small enums with the right vocabulary each beat one that has to be read twice.
 *
 * The direction is explicit and the amount is always positive, for the reason
 * recorded in D31: a signed amount column reads badly everywhere it surfaces, and a
 * validation rule permitting negative money in exactly one table is a rule somebody
 * will eventually relax by mistake.
 */
enum BalanceAdjustmentDirection: string
{
    /** The buyer owes more — a statement higher than the system expected. */
    case Increase = 'increase';

    /** The buyer owes less — a statement lower than the system expected. */
    case Decrease = 'decrease';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('buyers.adjustment_directions.'.$this->value);
    }

    /** +1 when it raises the receivable, -1 when it lowers it. */
    public function sign(): int
    {
        return $this === self::Increase ? 1 : -1;
    }

    /** The direction a money difference implies: positive raises what is owed. */
    public static function forDifference(string $difference): self
    {
        return bccomp($difference, '0.00', 2) >= 0 ? self::Increase : self::Decrease;
    }

    public function badge(): string
    {
        return $this === self::Increase
            ? 'text-warning-emphasis bg-warning-subtle border border-warning-subtle'
            : 'text-success-emphasis bg-success-subtle border border-success-subtle';
    }

    public function icon(): string
    {
        return $this === self::Increase ? 'arrow-up-circle' : 'arrow-down-circle';
    }
}
