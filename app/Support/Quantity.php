<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact arithmetic for milk quantities, in litres to three decimal places.
 *
 * Milk gets the same treatment money does: `DECIMAL(10,3)` in the database and
 * decimal strings through bcmath in PHP, never a binary float. The reconciliation
 * screen is the reason it matters. Given 10.000 litres allocated as
 * 3.333 + 3.333 + 3.334, the remaining figure must be exactly 0.000. In binary
 * floating point it is not, and the difference is not cosmetic: a residue of
 * -0.0000000001 makes a "remaining must not go negative" check reject a
 * perfectly balanced day, while a residue of +0.0000000001 lets 0.001 litres be
 * allocated out of nothing.
 *
 * This is deliberately a handful of static helpers rather than a units library.
 * The only unit is the litre and the only scale is three.
 */
final class Quantity
{
    /** Litres are recorded to millilitre precision. */
    public const SCALE = 3;

    public const ZERO = '0.000';

    /**
     * Coerces a numeric value to a canonical three-decimal string.
     *
     * `null` and `''` mean "absent" and become zero, which is what the callers
     * that pass an unset column or a `SUM()` over no rows need.
     *
     * Anything else non-numeric **throws**. Returning zero for it would be the
     * more forgiving choice and the wrong one: `'12,500'` — a comma decimal
     * separator, which is entirely plausible from an import or a pasted figure —
     * would silently record 0.000 litres instead of twelve and a half. In a
     * module built around the rule that a missing quantity is not a zero
     * quantity, quietly inventing a zero is the same mistake wearing a different
     * hat. A malformed quantity is a programming error at the call site, and it
     * fails there rather than three screens later in a reconciliation total.
     *
     * @throws InvalidArgumentException when the value is not numeric
     */
    public static function of(string|float|int|null $value): string
    {
        if ($value === null || $value === '') {
            return self::ZERO;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(
                'A milk quantity must be numeric; received '.var_export($value, true).'.'
            );
        }

        return bcadd((string) $value, '0', self::SCALE);
    }

    public static function add(string|float|int|null $a, string|float|int|null $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string|float|int|null $a, string|float|int|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    /** -1, 0 or 1, comparing at three decimal places. */
    public static function compare(string|float|int|null $a, string|float|int|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    /**
     * Sums an iterable of quantities.
     *
     * @param  iterable<mixed>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = self::ZERO;

        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    /** Negates a quantity, for applying an explicit direction. */
    public static function negate(string|float|int|null $value): string
    {
        return bcmul(self::of($value), '-1', self::SCALE);
    }

    public static function isZero(string|float|int|null $value): bool
    {
        return self::compare($value, self::ZERO) === 0;
    }

    public static function isNegative(string|float|int|null $value): bool
    {
        return self::compare($value, self::ZERO) < 0;
    }

    public static function isPositive(string|float|int|null $value): bool
    {
        return self::compare($value, self::ZERO) > 0;
    }

    /**
     * Applies a sign to a quantity. Used where an explicit direction has to
     * become arithmetic, so the signed value exists only in the calculation and
     * never in a column.
     */
    public static function signed(string|float|int|null $value, int $sign): string
    {
        return $sign < 0 ? self::negate($value) : self::of($value);
    }

    /** Money is held to two decimal places, as everywhere else in this project. */
    public const MONEY_SCALE = 2;

    /**
     * Litres times a rate per litre, as a money amount.
     *
     * The one place a quantity becomes an amount, so the rounding decision is made
     * once and visibly. A quantity has three decimal places and a rate has two, so
     * their product has up to five — 1.333 L at 70.55 is exactly 94.04315 — and
     * something has to decide the paisa.
     *
     * The product is computed exactly at scale 5 first, then rounded **half away
     * from zero** to two places: 94.04315 becomes 94.04, and a trailing 5 rounds up
     * rather than being discarded. bcmath truncates, so the rounding is explicit
     * rather than left to `bcmul`'s scale argument — truncating would quietly
     * under-bill every customer whose litres do not divide neatly, and by a
     * consistent direction, which is how a rounding bug turns into a real shortfall
     * over a few thousand deliveries.
     *
     * @param  string|float|int|null  $quantity  litres, three decimal places
     * @param  string|float|int|null  $rate  rupees per litre, two decimal places
     * @return string a money amount at two decimal places
     */
    public static function multiplyToMoney(
        string|float|int|null $quantity,
        string|float|int|null $rate,
    ): string {
        // scale 5 is exact for a 3-decimal quantity times a 2-decimal rate.
        $exact = bcmul(self::of($quantity), self::money($rate), self::SCALE + self::MONEY_SCALE);

        return self::roundHalfUp($exact, self::MONEY_SCALE);
    }

    /** Normalises a money-scaled value, with the same contract as of(). */
    public static function money(string|float|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(
                'A money value must be numeric; received '.var_export($value, true).'.'
            );
        }

        return bcadd((string) $value, '0', self::MONEY_SCALE);
    }

    /**
     * Sums an iterable of money values, exactly.
     *
     * The counterpart to {@see sum()} at money scale, for the places that add up rows
     * already in memory. A `Collection::sum()` there would add floats and lose a paisa
     * on a long enough list.
     *
     * @param  iterable<mixed>  $values
     */
    public static function sumMoney(iterable $values): string
    {
        $total = '0.00';

        foreach ($values as $value) {
            $total = bcadd($total, self::money($value), self::MONEY_SCALE);
        }

        return $total;
    }

    /** Negates a money value, for a payment's effect on a balance. */
    public static function negateMoney(string|float|int|null $value): string
    {
        return bcmul(self::money($value), '-1', self::MONEY_SCALE);
    }

    /**
     * Rounds a decimal string half away from zero, since bcmath only truncates.
     *
     * Adding half of the target unit before truncating gives half-up for positive
     * values; the sign is handled so a negative amount rounds away from zero too
     * rather than towards it.
     */
    private static function roundHalfUp(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }
}
