<?php

declare(strict_types=1);

namespace App\Support\Milk;

use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\CustomerPreference;
use App\Models\MilkSale;
use App\Support\Customers\DeliveryEligibility;
use App\Support\Quantity;

/**
 * One row of the Customer Daily Entry grid: one customer, one milk type, two shifts.
 *
 * The grid is a customer-by-milk-type matrix rather than a customer list, because a
 * customer who takes both cow and buffalo buys two different products at two
 * different rates. Flattening them onto one row would mean one rate column for two
 * rates, which is how a buffalo delivery ends up billed at the cow price.
 *
 * Everything the screen needs is computed here, in PHP, from values the grid service
 * already loaded. Blade renders this object; it does not calculate from it.
 *
 * **It carries reminders and it carries no prefill.** `reminder()` returns the
 * preference for the helper text and `savedQuantity()` returns what was actually
 * recorded — which is null when nothing was. The two are never interchangeable, and
 * nothing here collapses them into one "value for the input" accessor, because that
 * accessor is precisely the bug D39 exists to make impossible.
 */
final readonly class DailyEntryRow
{
    /**
     * @param  array<string, MilkSale>  $sales  existing grid sales keyed by shift value
     * @param  string|null  $resolvedRate  the selected date's rate, null when unresolvable
     */
    public function __construct(
        public Buyer $customer,
        public MilkType $milkType,
        public DeliveryEligibility $eligibility,
        public ?CustomerPreference $reminder,
        public array $sales,
        public ?string $resolvedRate,
    ) {}

    public function key(): string
    {
        return $this->customer->getKey().':'.$this->milkType->value;
    }

    /** The sale already recorded for a shift, cancelled ones included. */
    public function saleFor(Shift $shift): ?MilkSale
    {
        return $this->sales[$shift->value] ?? null;
    }

    /**
     * The quantity to put in the input, or null to leave it empty.
     *
     * A cancelled sale returns null: the delivery was withdrawn, so the field is
     * empty and re-typing a figure is how it comes back. This is the **only**
     * accessor the view uses for an input value, and it never consults a reminder.
     */
    public function savedQuantity(Shift $shift): ?string
    {
        $sale = $this->saleFor($shift);

        return $sale === null || $sale->isCancelled()
            ? null
            : Quantity::of($sale->quantity);
    }

    /**
     * The rate that applies to one cell.
     *
     * An existing row keeps its snapshot, whatever the resolver says today — a
     * cancelled one included, because re-entering the quantity revives that row and
     * its stored rate. Only a cell with no row at all is priced from the selected
     * date.
     */
    public function rateFor(Shift $shift): ?string
    {
        $sale = $this->saleFor($shift);

        return $sale !== null
            ? Quantity::money($sale->unit_rate)
            : $this->resolvedRate;
    }

    /** Whether a cell's rate is a stored snapshot rather than today's resolution. */
    public function rateIsSnapshot(Shift $shift): bool
    {
        return $this->saleFor($shift) !== null;
    }

    /**
     * The one rate to show, or null when the two shifts do not share one.
     *
     * Null is the honest answer in two different situations, and the view handles
     * both by listing the shifts separately: the rates genuinely differ, or one
     * shift is priced and the other cannot be.
     */
    public function displayRate(): ?string
    {
        $morning = $this->rateFor(Shift::Morning);
        $evening = $this->rateFor(Shift::Evening);

        if ($morning === null || $evening === null) {
            return null;
        }

        return bccomp($morning, $evening, Quantity::MONEY_SCALE) === 0 ? $morning : null;
    }

    /**
     * Whether the two shifts are priced differently, and must be shown separately.
     *
     * Rare but entirely real. A row holds **two** sale records, and D41 keeps each
     * one's rate exactly as it was recorded. So a morning saved at ₹70.00, followed
     * by a buyer override covering that same date, leaves an evening that would be
     * priced at ₹72.00 — and both figures are correct.
     *
     * A single "Rate" cell showing ₹70.00 would then be a lie about half the row,
     * and the row total would look like arithmetic nobody could reproduce: 2.000 L
     * costing ₹142.00 rather than ₹140.00. The column shows both instead.
     */
    public function hasMixedRates(): bool
    {
        return $this->displayRate() === null
            && ($this->rateFor(Shift::Morning) !== null || $this->rateFor(Shift::Evening) !== null);
    }

    /** Whether a *new* delivery could be priced at all on this date. */
    public function hasPrice(): bool
    {
        return $this->resolvedRate !== null;
    }

    /**
     * Whether one cell can be filled in.
     *
     * Deliberately per shift rather than per row. A row can hold a morning sale
     * recorded at a rate that no longer resolves — the price rule was corrected or
     * withdrawn afterwards — and the two shifts are then in genuinely different
     * positions: the morning has its own snapshot and can be corrected or cleared,
     * while the evening has nothing to price a new delivery with.
     *
     * Marking the whole row dead would take away the ability to fix a recorded
     * delivery. Marking the whole row live would invite an entry the server is bound
     * to refuse. Neither is right, so the answer is given one cell at a time.
     */
    public function isEditableFor(Shift $shift): bool
    {
        if ($this->isPaused()) {
            return false;
        }

        // An existing row carries its own rate, which is enough to re-cost or clear
        // it. A blank cell needs a rate for the selected date.
        return $this->saleFor($shift) !== null || $this->hasPrice();
    }

    public function isPaused(): bool
    {
        return $this->eligibility->isPaused;
    }

    /**
     * Whether any cell in this row can be filled in.
     *
     * The row-level question, answered from the per-cell one: a row is usable if at
     * least one of its shifts is. Used to decide whether the row takes part in a save
     * at all; which of its two cells are open is {@see isEditableFor()}.
     */
    public function isEditable(): bool
    {
        foreach (Shift::cases() as $shift) {
            if ($this->isEditableFor($shift)) {
                return true;
            }
        }

        return false;
    }

    /** Whether anything is currently recorded for this row. */
    public function hasActiveSale(): bool
    {
        foreach (Shift::cases() as $shift) {
            if ($this->savedQuantity($shift) !== null) {
                return true;
            }
        }

        return false;
    }

    public function total(): string
    {
        return Quantity::sum(array_map(
            fn (Shift $shift): string => $this->savedQuantity($shift) ?? Quantity::ZERO,
            Shift::cases(),
        ));
    }

    /** The row's money total, each shift priced by its own rate. */
    public function amount(): string
    {
        $amount = '0.00';

        foreach (Shift::cases() as $shift) {
            $quantity = $this->savedQuantity($shift);
            $rate = $this->rateFor($shift);

            if ($quantity !== null && $rate !== null) {
                $amount = bcadd($amount, Quantity::multiplyToMoney($quantity, $rate), Quantity::MONEY_SCALE);
            }
        }

        return $amount;
    }

    /**
     * One status for the row, in the order the operator needs to know it.
     *
     * Paused first because it explains why the fields are dead; a missing price
     * second for the same reason; then whether anything is recorded.
     */
    public function status(): string
    {
        return match (true) {
            $this->isPaused() => 'paused',
            ! $this->hasPrice() && $this->sales === [] => 'price_missing',
            $this->hasActiveSale() => 'saved',
            default => 'empty',
        };
    }

    /** The area and delivery note the customer cell shows underneath the name. */
    public function caption(): ?string
    {
        $parts = array_filter([
            $this->customer->area,
            $this->eligibility->deliveryNote,
        ], fn (?string $part): bool => $part !== null && trim($part) !== '');

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * The row as the browser needs it: quantities in integer thousandths and rates
     * in integer paise, so the live totals are summed as integers rather than
     * floats.
     *
     * @return array<string, mixed>
     */
    public function toClientPayload(): array
    {
        $cells = [];

        foreach (Shift::cases() as $shift) {
            $rate = $this->rateFor($shift);

            $cells[$shift->value] = [
                'saved' => $this->savedQuantity($shift),
                // Per cell, because the two shifts can legitimately differ (D43).
                'ratePaise' => $rate === null ? null : (int) bcmul($rate, '100', 0),
                'editable' => $this->isEditableFor($shift),
            ];
        }

        return [
            'buyerId' => $this->customer->getKey(),
            'milkType' => $this->milkType->value,
            'editable' => $this->isEditable(),
            'status' => $this->status(),
            'cells' => $cells,
        ];
    }
}
