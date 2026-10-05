<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Models\MilkSale;
use App\Services\AuditLogger;
use App\Services\Buyers\SettlementGuard;
use App\Services\Milk\MilkAvailability;
use App\Services\Milk\MilkSaleSlips;
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a Mandali, vendor or generic sale.
 *
 * Three rules shape it, each the same rule applied one level further out than the
 * last.
 *
 * **The quantity re-checks availability without double-counting itself.** Raising
 * 2.000 litres to 3.000 needs one more litre free, not three, so the row's current
 * quantity is discounted from what is already allocated — the same `excludingQuantity`
 * mechanism the daily grid uses (D35).
 *
 * **The rate is not re-resolved by a quantity edit.** Correcting the litres recomputes
 * the amount from the rate the row already carries. This is D41, which Phase 4
 * established for customer sales and which applies here for the same reason: a price
 * rule added later must not silently re-price a delivery that may already have been
 * invoiced. Changing the rate is a separate, explicit act that needs
 * `milk.sale.override_rate` and a reason.
 *
 * **A settled period is closed.** If a finalized settlement covers the sale's date,
 * the correction is refused until that settlement is cancelled. See
 * {@see SettlementGuard} for why both of the alternatives are worse.
 */
class UpdateChannelSale
{
    public function __construct(
        private readonly MilkAvailability $availability,
        private readonly MilkSaleSlips $slips,
        private readonly SettlementGuard $settlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  quantity, unit_rate, fat_percentage,
     *                                            snf_percentage, notes,
     *                                            rate_override_reason, slip,
     *                                            remove_slip
     */
    public function handle(MilkSale $sale, array $attributes): MilkSale
    {
        if ($sale->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('buyers.errors.sale_cancelled'),
            ]);
        }

        // Refused before anything is locked or written: the answer does not depend on
        // what the correction says.
        $this->settlements->assertNotSettled($sale);

        return DB::transaction(function () use ($sale, $attributes): MilkSale {
            $locked = MilkSale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            $before = [
                'quantity' => Quantity::of($locked->quantity),
                'unit_rate' => Quantity::money($locked->unit_rate),
                'amount' => Quantity::money($locked->amount),
                'fat_percentage' => $locked->fat_percentage,
                'snf_percentage' => $locked->snf_percentage,
                'notes' => $locked->notes,
                /*
                 * The slip's *display* name and whether there is one at all — never
                 * the stored path, which is an internal detail of the private disk,
                 * and never anything about the contents.
                 *
                 * Present because an empty diff writes no audit record: without these
                 * two, swapping or removing the document that supports a delivery
                 * would leave no trace, which is the opposite of what an attachment
                 * on a commercial record needs.
                 */
                'slip_attached' => $locked->hasSlip(),
                'slip_name' => $locked->slip_name,
            ];

            $quantity = $this->resolveQuantity($locked, $attributes);
            $rate = $this->resolveRate($locked, $attributes);

            // Only when the quantity actually rises does the shift need more milk.
            if (Quantity::compare($quantity, $locked->quantity) !== 0) {
                $this->assertAvailable($locked, $quantity);
            }

            $changes = [
                'quantity' => $quantity,
                'unit_rate' => $rate['unit_rate'],
                'amount' => Quantity::multiplyToMoney($quantity, $rate['unit_rate']),
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $locked->notes,
                'updated_by' => Auth::id(),
            ];

            if ($rate['overridden']) {
                $changes['resolved_rate'] = $rate['resolved_rate'];
                $changes['rate_override_reason'] = $rate['rate_override_reason'];
            }

            /*
             * Fat and SNF are editable on a Mandali delivery — a misread meter is a
             * normal correction — and they still reach no calculation. The amount
             * above is computed before they are touched, from quantity and rate
             * alone.
             */
            if ($locked->source->recordsMilkQuality()) {
                if (array_key_exists('fat_percentage', $attributes)) {
                    $changes['fat_percentage'] = $this->quality($attributes['fat_percentage']);
                }

                if (array_key_exists('snf_percentage', $attributes)) {
                    $changes['snf_percentage'] = $this->quality($attributes['snf_percentage']);
                }
            }

            $changes = array_merge($changes, $this->resolveSlip($locked, $attributes));

            $locked->forceFill($changes)->save();

            $after = [
                'quantity' => $changes['quantity'],
                'unit_rate' => $changes['unit_rate'],
                'amount' => $changes['amount'],
                'fat_percentage' => $changes['fat_percentage'] ?? $locked->fat_percentage,
                'snf_percentage' => $changes['snf_percentage'] ?? $locked->snf_percentage,
                'notes' => $changes['notes'],
                'slip_attached' => $locked->hasSlip(),
                'slip_name' => $locked->slip_name,
            ];

            if ($rate['overridden']) {
                $after['resolved_rate'] = $rate['resolved_rate'];
                $after['rate_override_reason'] = $rate['rate_override_reason'];
            }

            $this->audit->updated($locked, $before, $after, $this->subject($locked));

            return $locked->refresh();
        });
    }

    private function resolveQuantity(MilkSale $sale, array $attributes): string
    {
        if (! array_key_exists('quantity', $attributes)) {
            return Quantity::of($sale->quantity);
        }

        $quantity = Quantity::of($attributes['quantity']);

        if (! Quantity::isPositive($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => __('customers.errors.sale_quantity_positive'),
            ]);
        }

        return $quantity;
    }

    /**
     * Keeps the stored rate unless a rate change was explicitly requested.
     *
     * @return array{unit_rate: string, overridden: bool, resolved_rate: string|null, rate_override_reason: string|null}
     */
    private function resolveRate(MilkSale $sale, array $attributes): array
    {
        $current = Quantity::money($sale->unit_rate);

        if (! array_key_exists('unit_rate', $attributes) || blank($attributes['unit_rate'])) {
            return [
                'unit_rate' => $current,
                'overridden' => false,
                'resolved_rate' => null,
                'rate_override_reason' => null,
            ];
        }

        $wanted = Quantity::money($attributes['unit_rate']);

        if (bccomp($wanted, $current, Quantity::MONEY_SCALE) === 0) {
            return [
                'unit_rate' => $current,
                'overridden' => false,
                'resolved_rate' => null,
                'rate_override_reason' => null,
            ];
        }

        if (bccomp($wanted, '0.00', Quantity::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'unit_rate' => __('buyers.errors.rate_positive'),
            ]);
        }

        /*
         * Changing the rate on a recorded sale is a departure from what was agreed
         * when it was entered, whatever channel it belongs to, so it needs the
         * override permission and a reason — including on a Mandali delivery, where
         * typing the rate at *creation* needed neither.
         */
        if (Gate::denies(RecordChannelSale::OVERRIDE_PERMISSION)) {
            throw ValidationException::withMessages([
                'unit_rate' => __('buyers.errors.rate_change_not_permitted'),
            ]);
        }

        $reason = $attributes['rate_override_reason'] ?? null;

        if ($reason === null || trim((string) $reason) === '') {
            throw ValidationException::withMessages([
                'rate_override_reason' => __('buyers.errors.override_reason_required'),
            ]);
        }

        return [
            'unit_rate' => $wanted,
            'overridden' => true,
            // What the row was priced at before this change, which is the figure the
            // override departed from.
            'resolved_rate' => $sale->resolved_rate !== null ? Quantity::money($sale->resolved_rate) : $current,
            'rate_override_reason' => trim((string) $reason),
        ];
    }

    /** @return array<string, string|null> */
    private function resolveSlip(MilkSale $sale, array $attributes): array
    {
        $slip = $attributes['slip'] ?? null;

        if ($slip instanceof UploadedFile) {
            return $this->slips->replace($sale, $slip);
        }

        if (($attributes['remove_slip'] ?? false) && $sale->hasSlip()) {
            return $this->slips->remove($sale);
        }

        return [];
    }

    /** @throws ValidationException */
    private function assertAvailable(MilkSale $sale, string $quantity): void
    {
        MilkSale::query()
            ->active()
            ->forShift($sale->farm_id, $sale->sale_date->toDateString(), $sale->shift, $sale->milk_type)
            ->lockForUpdate()
            ->get();

        $this->availability->assertCanAllocate(
            farmId: $sale->farm_id,
            date: $sale->sale_date->toDateString(),
            shift: $sale->shift,
            milkType: $sale->milk_type,
            quantity: $quantity,
            // The row's own allocation is being replaced, not added to.
            excludingQuantity: Quantity::of($sale->quantity),
        );
    }

    private function quality(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : Quantity::money($value);
    }

    private function subject(MilkSale $sale): string
    {
        $sale->loadMissing('buyer:id,name');

        return sprintf(
            '%s — %s — %s — %s',
            $sale->buyer?->name ?? '',
            $sale->sale_date->format('d-m-Y'),
            $sale->shift->label(),
            $sale->milk_type->label(),
        );
    }
}
