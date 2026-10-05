<?php

declare(strict_types=1);

namespace App\Support\Milk;

use App\Contracts\MilkSalesAllocator;
use App\Support\Quantity;

/**
 * How much milk was sold in a shift, broken down by sales channel.
 *
 * The reconciliation screen has to show Mandali, Vendors, Direct Customers and
 * Other Sales as separate lines (MASTER_SPEC section 15), but the workflows that
 * record those sales arrive in Phases 4 and 5. This object is the shape the
 * engine already speaks, so attaching the real data later adds a provider and
 * changes nothing in the calculation or the view.
 *
 * `subsystemExists` is the honest part. While it is false the screen says the
 * sales modules are not built yet, rather than displaying four confident zeroes
 * that a reader would take to mean "nothing was sold today".
 *
 * @see MilkSalesAllocator
 */
final readonly class SalesAllocation
{
    /**
     * @param  array<string, string>  $byChannel  channel slug => quantity
     */
    private function __construct(
        public bool $subsystemExists,
        public array $byChannel,
        public string $total,
    ) {}

    /**
     * No sales subsystem exists yet, so nothing can have been sold.
     *
     * The total is a true zero -- there are no milk_sales rows to miss -- but
     * `subsystemExists` records *why* it is zero, which is the difference between
     * "no sales today" and "sales are not implemented".
     */
    public static function noSubsystem(): self
    {
        return new self(subsystemExists: false, byChannel: [], total: Quantity::ZERO);
    }

    /**
     * @param  array<string, string>  $byChannel
     */
    public static function fromChannels(array $byChannel): self
    {
        $normalised = [];

        foreach ($byChannel as $slug => $quantity) {
            $normalised[$slug] = Quantity::of($quantity);
        }

        return new self(
            subsystemExists: true,
            byChannel: $normalised,
            total: Quantity::sum($normalised),
        );
    }

    /** A channel's quantity, zero when that channel sold nothing. */
    public function forChannel(string $slug): string
    {
        return $this->byChannel[$slug] ?? Quantity::ZERO;
    }

    public function isEmpty(): bool
    {
        return Quantity::isZero($this->total);
    }
}
