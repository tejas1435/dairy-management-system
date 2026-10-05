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
use App\Services\Customers\CustomerEligibilityService;
use App\Services\Customers\CustomerPauseService;
use App\Services\Milk\MilkAvailability;
use App\Services\PriceResolver;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves one cell of the Customer Daily Entry grid, idempotently.
 *
 * The grid itself is Pass 2. This is the domain operation it will call once per
 * changed cell, and it is deliberately built and tested first so that the most
 * important screen in the product sits on something already proven.
 *
 * The identity is customer + date + shift + milk type + grid source, which the
 * database holds unique through a generated column (docs/DECISIONS.md D38). So:
 *
 *   - a quantity where there was none **creates** the sale;
 *   - a different quantity **updates** the existing row and recomputes the amount;
 *   - clearing the quantity **cancels** the row rather than deleting it;
 *   - re-entering a quantity afterwards **reactivates that same row**.
 *
 * The last case deserves its reasoning. The unique key covers grid rows regardless
 * of status, so a cancelled row and a fresh replacement cannot coexist — one row per
 * customer, date, shift and milk type is the guarantee, and weakening it to allow a
 * second row would also allow the duplicates the key exists to prevent. The
 * reactivation therefore reuses the row, and **the history lives in the audit log**,
 * which holds the withdrawal and the re-entry as separate events with their actors
 * and timestamps. A customer disputing a month can still be shown that the delivery
 * was removed on one day and re-entered on another.
 */
class SaveCustomerDailySale
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly MilkAvailability $availability,
        private readonly CustomerEligibilityService $eligibility,
        private readonly CustomerPauseService $pauses,
        private readonly CancelMilkSale $cancel,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /**
     * Records the quantity delivered to one customer for one shift and milk type.
     *
     * A null or zero quantity means "nothing delivered", which cancels any existing
     * sale rather than storing a zero.
     *
     * @return MilkSale|null the resulting sale, or null when the cell is now empty
     */
    public function handle(
        Buyer $customer,
        string $date,
        Shift $shift,
        MilkType $milkType,
        ?string $quantity,
        ?int $farmId = null,
    ): ?MilkSale {
        $farmId ??= $this->context->primaryFarmId();

        $this->eligibility->assertDirectCustomer($customer);
        $this->assertDeliverable($customer, $date, $milkType);

        $quantity = $quantity === null || trim($quantity) === ''
            ? null
            : Quantity::of($quantity);

        return DB::transaction(function () use ($customer, $farmId, $date, $shift, $milkType, $quantity): ?MilkSale {
            $existing = MilkSale::query()
                ->dailyGridRow($farmId, $customer->getKey(), $date, $shift, $milkType)
                ->lockForUpdate()
                ->first();

            // Nothing delivered: withdraw whatever was there, or do nothing.
            if ($quantity === null || Quantity::isZero($quantity)) {
                if ($existing && ! $existing->isCancelled()) {
                    $this->cancel->becauseRemovedFromGrid($existing);
                }

                return null;
            }

            return $existing
                ? $this->update($existing, $customer, $date, $milkType, $quantity, $farmId, $shift)
                : $this->create($customer, $farmId, $date, $shift, $milkType, $quantity);
        });
    }

    /**
     * Saves a whole day for one customer: both shifts, one milk type.
     *
     * @param  array<string, string|null>  $quantities  keyed by Shift value
     * @return array<string, MilkSale|null>
     */
    public function forDay(
        Buyer $customer,
        string $date,
        MilkType $milkType,
        array $quantities,
        ?int $farmId = null,
    ): array {
        $farmId ??= $this->context->primaryFarmId();

        return DB::transaction(function () use ($customer, $date, $milkType, $quantities, $farmId): array {
            $saved = [];

            foreach (Shift::cases() as $shift) {
                if (! array_key_exists($shift->value, $quantities)) {
                    continue;
                }

                $saved[$shift->value] = $this->handle(
                    customer: $customer,
                    date: $date,
                    shift: $shift,
                    milkType: $milkType,
                    quantity: $quantities[$shift->value],
                    farmId: $farmId,
                );
            }

            return $saved;
        });
    }

    /**
     * The previous day's grid quantities for a customer, keyed by milk type and
     * shift.
     *
     * The query behind Copy Previous Day, which Pass 2 will wire to an explicit
     * button. **Nothing calls this automatically** — no observer, no default, no
     * prefill. Copying yesterday is a decision somebody makes, and MASTER_SPEC
     * section 19 is explicit that nothing may be auto-copied.
     *
     * @return array<string, array<string, string>> [milk_type][shift] => quantity
     */
    public function previousDayQuantities(Buyer $customer, string $date, ?int $farmId = null): array
    {
        $farmId ??= $this->context->primaryFarmId();
        $previous = Carbon::parse($date)->subDay()->toDateString();

        $sales = MilkSale::query()
            ->active()
            ->fromSource(SaleSource::CustomerDailyGrid)
            ->where('farm_id', $farmId)
            ->where('buyer_id', $customer->getKey())
            ->whereDate('sale_date', $previous)
            ->get();

        /*
         * Keyed in enum order rather than whatever order the rows came back in, so a
         * caller building a grid from this gets a stable shape. Empty milk types and
         * shifts are left out entirely: absent means nothing was delivered, which is
         * different from a zero.
         */
        $quantities = [];

        foreach (MilkType::cases() as $milkType) {
            foreach (Shift::cases() as $shift) {
                $sale = $sales->first(
                    fn (MilkSale $s): bool => $s->milk_type === $milkType && $s->shift === $shift
                );

                if ($sale !== null) {
                    $quantities[$milkType->value][$shift->value] = Quantity::of($sale->quantity);
                }
            }
        }

        return $quantities;
    }

    private function create(
        Buyer $customer,
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
    ): MilkSale {
        $rate = $this->resolveRate($customer, $milkType, $date);

        $this->assertAvailable($farmId, $date, $shift, $milkType, $quantity, null);

        $amount = Quantity::multiplyToMoney($quantity, $rate);

        $sale = MilkSale::create([
            'farm_id' => $farmId,
            'sales_channel_id' => $customer->sales_channel_id,
            'buyer_id' => $customer->getKey(),
            'sale_date' => $date,
            'shift' => $shift->value,
            'milk_type' => $milkType->value,
            'quantity' => $quantity,
            'unit_rate' => $rate,
            'amount' => $amount,
            'source' => SaleSource::CustomerDailyGrid->value,
            'status' => TransactionStatus::Active->value,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->created($sale, [
            'sale_date' => $date,
            'shift' => $shift->value,
            'milk_type' => $milkType->value,
            'buyer_id' => $customer->getKey(),
            'quantity' => $quantity,
            'unit_rate' => $rate,
            'amount' => $amount,
        ], $this->subject($customer, $sale));

        return $sale;
    }

    /**
     * Updates an existing grid row, or revives a cancelled one.
     *
     * **The stored rate is preserved, not re-resolved.** An earlier draft of this
     * action re-ran the resolver on every update, reasoning that resolution is by
     * sale date so the answer could not drift. That reasoning is wrong in the one
     * case that matters: a buyer override added later can cover a past date, and
     * re-resolving would then silently re-price a delivery that has already been
     * billed — simply because somebody reopened the day to fix a typo in the litres.
     *
     * A snapshot that can be overwritten is not a snapshot. So a quantity edit
     * recomputes `amount` from the rate **this row already carries**, and only a
     * brand-new row asks the resolver. Correcting the rate of a recorded sale is a
     * different operation from correcting its quantity, and it does not exist yet.
     */
    private function update(
        MilkSale $existing,
        Buyer $customer,
        string $date,
        MilkType $milkType,
        string $quantity,
        int $farmId,
        Shift $shift,
    ): MilkSale {
        $wasCancelled = $existing->isCancelled();

        /*
         * The quantity already on this row is discounted from the availability
         * check: replacing 2.000 litres with 3.000 needs one more litre free, not
         * three. A cancelled row contributes nothing, so it is not discounted.
         */
        $excluding = $wasCancelled ? null : Quantity::of($existing->quantity);

        $this->assertAvailable($farmId, $date, $shift, $milkType, $quantity, $excluding);

        // The row's own snapshot, taken when it was first saved. See the docblock.
        $rate = Quantity::money($existing->unit_rate);
        $amount = Quantity::multiplyToMoney($quantity, $rate);

        $before = [
            'quantity' => Quantity::of($existing->quantity),
            'unit_rate' => Quantity::money($existing->unit_rate),
            'amount' => Quantity::money($existing->amount),
            'status' => $existing->status->value,
        ];

        $existing->forceFill([
            'quantity' => $quantity,
            'unit_rate' => $rate,
            'amount' => $amount,
            'status' => TransactionStatus::Active->value,
            // Re-entering after a withdrawal clears the withdrawal, and the audit
            // trail keeps both events.
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'updated_by' => Auth::id(),
        ])->save();

        $this->audit->updated($existing, $before, [
            'quantity' => $quantity,
            'unit_rate' => $rate,
            'amount' => $amount,
            'status' => TransactionStatus::Active->value,
        ], $this->subject($customer, $existing));

        return $existing->refresh();
    }

    /** @throws ValidationException */
    private function assertAvailable(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
        ?string $excluding,
    ): void {
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
            excludingQuantity: $excluding,
        );
    }

    /**
     * Refuses a delivery the customer should not be receiving.
     *
     * Checked on the server even though Pass 2's grid will disable the fields
     * visually. A disabled input is a courtesy; the refusal is the rule
     * (MASTER_SPEC section 18).
     *
     * @throws ValidationException
     */
    private function assertDeliverable(Buyer $customer, string $date, MilkType $milkType): void
    {
        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.buyer_inactive', ['name' => $customer->name]),
            ]);
        }

        if (! $customer->hasStartedBy($date)) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.before_start_date', [
                    'name' => $customer->name,
                    'date' => $customer->start_date?->format('d-m-Y') ?? '',
                ]),
            ]);
        }

        if ($this->pauses->isPausedOn($customer, $date)) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.customer_paused', [
                    'name' => $customer->name,
                    'date' => Carbon::parse($date)->format('d-m-Y'),
                ]),
            ]);
        }

        if ($customer->activePreferenceFor($milkType) === null) {
            throw ValidationException::withMessages([
                'milk_type' => __('customers.errors.no_active_preference', [
                    'name' => $customer->name,
                    'type' => $milkType->label(),
                ]),
            ]);
        }
    }

    /** @throws ValidationException */
    private function resolveRate(Buyer $customer, MilkType $milkType, string $date): string
    {
        $resolved = $this->prices->resolve($customer, $milkType, $date);

        if (! $resolved->found) {
            throw ValidationException::withMessages(['unit_rate' => $resolved->reason()]);
        }

        return Quantity::money($resolved->rate());
    }

    private function subject(Buyer $customer, MilkSale $sale): string
    {
        return sprintf(
            '%s — %s — %s — %s',
            $customer->name,
            $sale->sale_date->format('d-m-Y'),
            $sale->shift->label(),
            $sale->milk_type->label(),
        );
    }
}
