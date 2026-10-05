<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Buyer;
use App\Models\SalesChannel;
use App\Models\User;

/**
 * Maps a sales channel to the permissions that govern its buyers.
 *
 * The specification gives Mandali, vendors and direct customers their own
 * permission families, but they share one `buyers` table. Rather than scatter
 * `if channel is mandali then check mandali.view` through controllers, policies
 * and Blade, that decision lives here and BuyerPolicy asks this class.
 *
 * Custom channels — a hotel, a sweet shop, a bulk buyer — map to the
 * `customer.*` family. Commercially they are direct buyers, and the generic
 * sale entry treats them exactly as it treats a direct customer, so borrowing
 * that family keeps a custom channel from either being unreachable or silently
 * inheriting Mandali settlement rights. Documented in docs/DECISIONS.md D26.
 */
final class BuyerPermissions
{
    /**
     * Permission family prefix per system channel slug.
     *
     * @return array<string, string>
     */
    public static function families(): array
    {
        return [
            SalesChannel::MANDALI => 'mandali',
            SalesChannel::VENDOR => 'vendor',
            SalesChannel::DIRECT_CUSTOMER => 'customer',
        ];
    }

    /** The fallback family for administrator-created channels. */
    public const CUSTOM_FAMILY = 'customer';

    public static function familyFor(SalesChannel|Buyer|string|null $subject): string
    {
        $slug = match (true) {
            $subject instanceof Buyer => $subject->salesChannel?->slug,
            $subject instanceof SalesChannel => $subject->slug,
            default => $subject,
        };

        return self::families()[$slug] ?? self::CUSTOM_FAMILY;
    }

    public static function view(SalesChannel|Buyer|string|null $subject): string
    {
        return self::familyFor($subject).'.view';
    }

    public static function create(SalesChannel|Buyer|string|null $subject): string
    {
        return self::familyFor($subject).'.create';
    }

    public static function update(SalesChannel|Buyer|string|null $subject): string
    {
        return self::familyFor($subject).'.update';
    }

    /**
     * Every "view" permission that could grant access to some buyer, used to
     * decide whether the buyer list is reachable at all.
     *
     * @return array<int, string>
     */
    public static function allViewPermissions(): array
    {
        $families = array_values(self::families());
        $families[] = self::CUSTOM_FAMILY;

        return array_values(array_unique(
            array_map(fn (string $family): string => $family.'.view', $families)
        ));
    }

    /**
     * The channel slugs a user may see, so the list can be filtered to them
     * rather than showing rows the server would refuse to open.
     *
     * @return array<int, string>
     */
    public static function visibleFamiliesFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $visible = [];

        foreach (self::families() as $slug => $family) {
            if ($user->can($family.'.view')) {
                $visible[] = $slug;
            }
        }

        return $visible;
    }

    public static function canSeeCustomChannels(?User $user): bool
    {
        return (bool) $user?->can(self::CUSTOM_FAMILY.'.view');
    }
}
