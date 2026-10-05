<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Resolves the business date a screen is looking at.
 *
 * Every operational screen takes a `?date=` parameter for navigation, and every
 * one of them has the same question to answer: what should happen when that
 * parameter is missing, empty or nonsense.
 *
 * The answer is today. A malformed navigation parameter is not submitted data --
 * nobody typed it into a field and no validation message has anywhere to appear --
 * so a 500 or a validation screen would be a worse response than simply showing
 * the current day. Dates that arrive through a form go through a Form Request and
 * are validated properly there.
 *
 * Business dates are DATE columns and carry no time (docs/DECISIONS.md D7), so
 * everything here is normalised to the start of the day.
 */
final class OperationalDate
{
    /** The requested date, or today when it is absent or unparseable. */
    public static function resolve(mixed $value): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return now()->startOfDay();
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    /** The resolved date as a `Y-m-d` string, ready for a DATE comparison. */
    public static function resolveString(mixed $value): string
    {
        return self::resolve($value)->toDateString();
    }
}
