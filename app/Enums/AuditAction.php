<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of change worth recording.
 *
 * Deliberately business-shaped rather than ORM-shaped: the log answers "what did
 * somebody do" and not "which model was touched". A save that only recalculates
 * a derived value is not an audit event.
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case Cancelled = 'cancelled';
    case Deleted = 'deleted';
    case RolesChanged = 'roles_changed';
    case PermissionsChanged = 'permissions_changed';
    case PrimaryChanged = 'primary_changed';
    case Funded = 'funded';
    case Reversed = 'reversed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('audit.actions.'.$this->value);
    }

    /** Bootstrap contextual colour for the badge in the viewer. */
    public function badge(): string
    {
        return match ($this) {
            self::Created, self::Activated, self::Funded => 'success',
            self::Updated, self::RolesChanged, self::PermissionsChanged, self::PrimaryChanged => 'primary',
            self::Deactivated, self::Reversed => 'warning',
            self::Cancelled, self::Deleted => 'danger',
        };
    }
}
