<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a Mandali settlement has got to (MASTER_SPEC section 23).
 *
 * A `VARCHAR` backed by this enum rather than a MySQL `ENUM`, like every other
 * enumeration in the schema: adding a state to a MySQL `ENUM` is a table alter, and
 * the set of states is a business decision that belongs in code where it can be read
 * and tested.
 *
 * The two payment states are **derived, never set**. `PartiallyPaid` and `Paid`
 * follow from the sum of active payments linked to the settlement, so there is no
 * way for the stored status to disagree with the receipts — which is the same reason
 * no balance is stored anywhere in this project. `transitionTo()` refuses to be
 * handed one of them directly.
 */
enum SettlementStatus: string
{
    /** Being prepared. Carries no accounting effect at all. */
    case Draft = 'draft';

    /** Agreed and snapshotted. Any difference has become a balance adjustment. */
    case Finalized = 'finalized';

    /** Finalized, with some but not all of the amount due received. */
    case PartiallyPaid = 'partially_paid';

    /** Finalized and settled in full. */
    case Paid = 'paid';

    /** Withdrawn. Its adjustment is cancelled with it; its history remains. */
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('buyers.settlement_statuses.'.$this->value);
    }

    /** Whether the settlement still counts: not withdrawn. */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Whether its snapshots and its adjustment are in force. */
    public function isFinalized(): bool
    {
        return in_array($this, [self::Finalized, self::PartiallyPaid, self::Paid], true);
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * Whether payments may be linked to a settlement in this state.
     *
     * Not a draft: a draft has no agreed amount to pay against, and no accounting
     * effect, so a receipt linked to one would be money against nothing.
     */
    public function acceptsPayment(): bool
    {
        return $this->isFinalized();
    }

    /**
     * The states this one may be moved to by an explicit domain action.
     *
     * Deliberately narrow. `PartiallyPaid` and `Paid` are absent from every list
     * because they are computed from linked payments rather than chosen, and nothing
     * leads out of `Cancelled` — a withdrawn settlement is re-done by creating
     * another one, so that the history of what was agreed and then withdrawn
     * survives.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Finalized, self::Cancelled],
            self::Finalized, self::PartiallyPaid => [self::Cancelled],
            // Paid is terminal unless the receipts behind it are withdrawn first,
            // which moves it back through the derived states on its own.
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle',
            self::Finalized => 'text-primary-emphasis bg-primary-subtle border border-primary-subtle',
            self::PartiallyPaid => 'text-warning-emphasis bg-warning-subtle border border-warning-subtle',
            self::Paid => 'text-success-emphasis bg-success-subtle border border-success-subtle',
            self::Cancelled => 'text-danger-emphasis bg-danger-subtle border border-danger-subtle',
        };
    }
}
