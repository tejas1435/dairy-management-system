<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\MilkSale;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Milk\MilkAvailability;
use App\Services\PriceResolver;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one milk sale, whatever channel it belongs to.
 *
 * The canonical write path. Phase 4's customer deliveries go through it; Phase 5's
 * Mandali, vendor and generic sales go through the same method with a different
 * buyer and source. Nothing about it is customer-shaped.
 *
 * Four things happen here that must not happen anywhere else:
 *
 *  1. **The rate is resolved and snapshotted.** `PriceResolver` answers for the
 *     buyer, the milk type and the *sale date* — never today — and the answer is
 *     copied into the row. A later price change cannot reach back and alter what
 *     last month cost. A missing price is an explicit refusal, not a zero.
 *  2. **The amount is computed server-side** as quantity x rate, through exact
 *     decimal arithmetic. Whatever total the browser displayed is ignored.
 *  3. **Availability is asserted inside the transaction**, after a lock, so a sale
 *     cannot take milk the shift does not have — and cannot do so by racing another
 *     writer. A bulk grid is not an exemption from this.
 *  4. **The farm comes from BusinessContext**, not from the request.
 */
class CreateMilkSale
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly MilkAvailability $availability,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /**
     * @param  string|null  $rate  an explicit rate, for a channel that sets its own —
     *                             a Mandali collection or a generic sale. Null
     *                             resolves from the price rules.
     * @param  string|null  $excludingQuantity  a quantity already counted towards this
     *                                          shift that this sale replaces, used when
     *                                          re-saving an existing row
     * @param  array<string, string|null>  $extra  Phase 5 columns: fat_percentage,
     *                                             snf_percentage, resolved_rate,
     *                                             rate_override_reason, slip_path,
     *                                             slip_name
     */
    public function handle(
        Buyer $buyer,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
        SaleSource $source = SaleSource::CustomerDailyGrid,
        ?string $rate = null,
        ?int $farmId = null,
        ?string $notes = null,
        ?string $excludingQuantity = null,
        array $extra = [],
    ): MilkSale {
        $farmId ??= $this->context->primaryFarmId();
        $quantity = Quantity::of($quantity);

        $this->assertQuantityIsPositive($quantity);
        $this->assertBuyerIsUsable($buyer);

        $unitRate = $rate !== null
            ? Quantity::money($rate)
            : $this->resolveRate($buyer, $milkType, $date);

        return DB::transaction(function () use (
            $buyer, $farmId, $date, $shift, $milkType, $quantity, $source, $unitRate, $notes, $excludingQuantity, $extra
        ): MilkSale {
            /*
             * Lock the shift's existing sales before measuring what is left.
             * Checking availability outside the transaction would leave the obvious
             * race: two writers each see 2.000 litres free and each sell 2.000.
             */
            MilkSale::query()
                ->active()
                ->forShift($farmId, $date, $shift, $milkType)
                ->lockForUpdate()
                ->get();

            $this->availability->assertCanAllocate(
                farmId: $farmId,
                date: $date,
                shift: $shift,
                milkType: $milkType,
                quantity: $quantity,
                excludingQuantity: $excludingQuantity,
            );

            $amount = Quantity::multiplyToMoney($quantity, $unitRate);

            /*
             * The Phase 5 columns, passed through rather than computed here: milk
             * quality is reference data, and the resolved rate and override reason
             * are provenance the calling workflow established. `amount` is still
             * computed above from `unit_rate` alone — fat and SNF reach the row and
             * nothing else (MASTER_SPEC section 22).
             */
            $sale = MilkSale::create([
                'farm_id' => $farmId,
                'sales_channel_id' => $buyer->sales_channel_id,
                'buyer_id' => $buyer->getKey(),
                'sale_date' => $date,
                'shift' => $shift->value,
                'milk_type' => $milkType->value,
                'quantity' => $quantity,
                'unit_rate' => $unitRate,
                'amount' => $amount,
                'source' => $source->value,
                'notes' => $notes,
                'fat_percentage' => $extra['fat_percentage'] ?? null,
                'snf_percentage' => $extra['snf_percentage'] ?? null,
                'resolved_rate' => $extra['resolved_rate'] ?? null,
                'rate_override_reason' => $extra['rate_override_reason'] ?? null,
                'slip_path' => $extra['slip_path'] ?? null,
                'slip_name' => $extra['slip_name'] ?? null,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            /*
             * The slip's path and original name are deliberately absent from the
             * audit payload, and so is anything about its contents. A stored path is
             * an internal detail of the private disk, and an audit log is read by
             * more people than the file is.
             */
            $this->audit->created($sale, [
                'sale_date' => $date,
                'shift' => $shift->value,
                'milk_type' => $milkType->value,
                'buyer_id' => $buyer->getKey(),
                'quantity' => $quantity,
                'unit_rate' => $unitRate,
                'amount' => $amount,
                'source' => $source->value,
                'fat_percentage' => $extra['fat_percentage'] ?? null,
                'snf_percentage' => $extra['snf_percentage'] ?? null,
                'resolved_rate' => $extra['resolved_rate'] ?? null,
                'rate_override_reason' => $extra['rate_override_reason'] ?? null,
                'slip_attached' => ($extra['slip_path'] ?? null) !== null,
            ], $this->subject($sale, $buyer));

            return $sale;
        });
    }

    /**
     * The rate that applies, or a refusal.
     *
     * `rate()` throws on a failed resolution rather than returning zero, so a sale
     * cannot be saved at a price nobody configured — the error surfaces here instead
     * of as wrong money weeks later (docs/DECISIONS.md D27).
     *
     * @throws ValidationException
     */
    private function resolveRate(Buyer $buyer, MilkType $milkType, string $date): string
    {
        $resolved = $this->prices->resolve($buyer, $milkType, $date);

        if (! $resolved->found) {
            throw ValidationException::withMessages([
                'unit_rate' => $resolved->reason(),
            ]);
        }

        return Quantity::money($resolved->rate());
    }

    /** @throws ValidationException */
    private function assertQuantityIsPositive(string $quantity): void
    {
        if (! Quantity::isPositive($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => __('customers.errors.sale_quantity_positive'),
            ]);
        }
    }

    /**
     * Refuses a buyer that cannot receive a sale.
     *
     * An inactive buyer is refused because recording a delivery to an archived
     * customer is almost always a mistake, and the archive is what stops them
     * appearing on the grid in the first place.
     *
     * @throws ValidationException
     */
    private function assertBuyerIsUsable(Buyer $buyer): void
    {
        if ((int) $buyer->business_id !== (int) $this->context->business()->getKey()) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.wrong_business'),
            ]);
        }

        if (! $buyer->is_active) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.buyer_inactive', ['name' => $buyer->name]),
            ]);
        }

        if ($buyer->sales_channel_id === null) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.buyer_without_channel'),
            ]);
        }
    }

    private function subject(MilkSale $sale, Buyer $buyer): string
    {
        return sprintf(
            '%s — %s — %s — %s',
            $buyer->name,
            $sale->sale_date->format('d-m-Y'),
            $sale->shift->label(),
            $sale->milk_type->label(),
        );
    }
}
