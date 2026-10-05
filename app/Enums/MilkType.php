<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Milk types the business handles.
 *
 * Persisted as a string rather than a MySQL ENUM so a third type is a code and
 * data change, not a schema migration (MASTER_SPEC section 13).
 */
enum MilkType: string
{
    case Cow = 'cow';
    case Buffalo = 'buffalo';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('pricing.milk_types.'.$this->value);
    }
}
