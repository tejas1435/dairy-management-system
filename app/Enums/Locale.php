<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The locales the application ships translations for.
 *
 * Stored on users as a short string rather than a MySQL ENUM so adding a
 * language later is a code change plus translation files, not a migration.
 */
enum Locale: string
{
    case English = 'en';
    case Gujarati = 'gu';
    case Hindi = 'hi';

    public static function default(): self
    {
        return self::English;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $locale): string => $locale->value, self::cases());
    }

    public static function tryFromOrDefault(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * The language's own name, shown in the switcher. Deliberately not
     * translated: a speaker looking for their language recognises it written
     * in that language, not in the one they cannot read.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Gujarati => 'ગુજરાતી',
            self::Hindi => 'हिन्दी',
        };
    }
}
