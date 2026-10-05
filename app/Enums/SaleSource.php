<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which workflow produced a milk sale.
 *
 * The value matters beyond bookkeeping: `customer_daily_grid` rows are the ones the
 * database holds to an idempotent identity, so re-saving a day updates them instead
 * of duplicating them (MASTER_SPEC section 20). Rows from any other source are not
 * constrained that way, because a generic sale form may legitimately record two
 * sales to the same buyer in one shift. The generated column in the
 * `milk_sales` migration keys off exactly this value.
 *
 * Phase 5 adds the three workflows that write through `CreateMilkSale` with a
 * different buyer and a different pricing rule. Each is a separate case rather than
 * one `other` because the source is what a report groups by and what tells a reader
 * which rules produced a row: a Mandali rate was typed by hand, a vendor rate was
 * resolved or deliberately overridden, and a generic rate was typed for a channel
 * that has no price rules of its own.
 */
enum SaleSource: string
{
    /**
     * The Customer Daily Entry grid — one row per customer, date, shift and milk
     * type, re-saved idempotently.
     */
    case CustomerDailyGrid = 'customer_daily_grid';

    /** A Mandali collection, with fat and SNF recorded and the rate typed by hand. */
    case MandaliDelivery = 'mandali_delivery';

    /** A sale to a local dairy or vendor, priced by the resolver or overridden. */
    case VendorSale = 'vendor_sale';

    /** The generic form for administrator-created channels — a hotel, a sweet shop. */
    case GenericSale = 'generic_sale';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return __('customers.sale_sources.'.$this->value);
    }

    /**
     * Whether this source is held to the one-row-per-shift identity.
     *
     * Kept as a method rather than a comparison at each call site, so the day a
     * second idempotent source appears there is one place to change.
     */
    public function isIdempotentPerShift(): bool
    {
        return $this === self::CustomerDailyGrid;
    }

    /**
     * Whether this workflow sets its own rate instead of resolving one.
     *
     * A Mandali rate is typed by hand every time — that is the normal workflow, not
     * an override (MASTER_SPEC section 22), and the same is true of the generic form,
     * whose channels have no price rules. A vendor sale resolves a rate and treats a
     * different typed figure as a deliberate, permissioned override.
     */
    public function usesManualRate(): bool
    {
        return $this === self::MandaliDelivery || $this === self::GenericSale;
    }

    /** Whether fat and SNF are recorded for this workflow, for reference only. */
    public function recordsMilkQuality(): bool
    {
        return $this === self::MandaliDelivery;
    }

    /** The sources an operator enters one sale at a time, newest first in lists. */
    public static function manualEntrySources(): array
    {
        return [self::MandaliDelivery, self::VendorSale, self::GenericSale];
    }
}
