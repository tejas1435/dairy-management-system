<?php

declare(strict_types=1);

namespace App\Actions\Milk;

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\MilkSale;
use App\Models\SalesChannel;
use App\Services\Buyers\SettlementGuard;
use App\Services\Milk\MilkSaleSlips;
use App\Services\PriceResolver;
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records one sale through a Phase 5 workflow: Mandali, vendor or generic.
 *
 * All three write the same canonical {@see MilkSale} through the same
 * {@see CreateMilkSale}, so availability, the farm, the amount and the audit record
 * behave identically. What differs between them is **how the rate is decided**, and
 * that is the whole reason this action exists rather than three near-copies:
 *
 * | Workflow | Rate |
 * | -------- | ---- |
 * | Mandali | typed by hand every time. Not an override — it is the workflow (MASTER_SPEC 22). Fat and SNF are recorded beside it and change nothing. |
 * | Vendor | resolved for the sale date. A *different* typed figure is a deliberate override needing `milk.sale.override_rate` and a reason. |
 * | Generic | typed by hand. A custom channel has no price rules, so there is nothing to resolve or depart from. |
 *
 * The distinction matters because "typed a rate" and "overrode the rate" are
 * different acts. Treating every typed Mandali rate as an override would demand the
 * override permission for ordinary daily work and fill the audit log with overrides
 * that departed from nothing; treating a changed vendor rate as ordinary would let
 * an agreed price be quietly undercut.
 */
class RecordChannelSale
{
    public const OVERRIDE_PERMISSION = 'milk.sale.override_rate';

    public function __construct(
        private readonly CreateMilkSale $createSale,
        private readonly PriceResolver $prices,
        private readonly MilkSaleSlips $slips,
        private readonly SettlementGuard $settlements,
    ) {}

    /**
     * @param  string|null  $rate  the typed rate. Required for Mandali and generic
     *                             sales; for a vendor, null means "use the resolved
     *                             rate".
     * @param  array<string, mixed>  $attributes  fat_percentage, snf_percentage,
     *                                            notes, rate_override_reason, slip
     */
    public function handle(
        Buyer $buyer,
        SaleSource $source,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
        ?string $rate = null,
        array $attributes = [],
    ): MilkSale {
        $this->assertChannelMatchesSource($buyer, $source);

        /*
         * A delivery inside an already-settled period is refused for the same reason
         * a correction to one is: the settlement's figures are closed, and milk added
         * afterwards would be milk nobody agreed to pay for.
         */
        $this->settlements->assertDateNotSettled($buyer->getKey(), $date);

        $pricing = $this->resolvePricing($buyer, $source, $milkType, $date, $rate, $attributes);

        $extra = $pricing;
        unset($extra['applied_rate']);

        if ($source->recordsMilkQuality()) {
            $extra['fat_percentage'] = $this->quality($attributes['fat_percentage'] ?? null);
            $extra['snf_percentage'] = $this->quality($attributes['snf_percentage'] ?? null);
        }

        $slip = $attributes['slip'] ?? null;

        if ($slip instanceof UploadedFile) {
            $extra = array_merge($extra, $this->slips->store($slip));
        }

        return $this->createSale->handle(
            buyer: $buyer,
            date: $date,
            shift: $shift,
            milkType: $milkType,
            quantity: $quantity,
            source: $source,
            rate: $pricing['applied_rate'],
            notes: $attributes['notes'] ?? null,
            extra: $extra,
        );
    }

    /**
     * Works out the rate to apply and the provenance to store with it.
     *
     * @return array{applied_rate: string, resolved_rate: string|null, rate_override_reason: string|null}
     *
     * @throws ValidationException
     */
    private function resolvePricing(
        Buyer $buyer,
        SaleSource $source,
        MilkType $milkType,
        string $date,
        ?string $rate,
        array $attributes,
    ): array {
        // Mandali and generic: the typed rate is the rate, and it is required.
        if ($source->usesManualRate()) {
            return [
                'applied_rate' => $this->requireManualRate($rate),
                'resolved_rate' => null,
                'rate_override_reason' => null,
            ];
        }

        // Vendor: start from what the resolver says for the sale date.
        $resolved = $this->prices->resolve($buyer, $milkType, $date);
        $resolvedRate = $resolved->found ? Quantity::money($resolved->rate()) : null;
        $typed = $rate === null || trim($rate) === '' ? null : Quantity::money($rate);

        // Nothing typed: take the configured rate, or refuse if there is none.
        if ($typed === null) {
            if ($resolvedRate === null) {
                throw ValidationException::withMessages(['unit_rate' => $resolved->reason()]);
            }

            return [
                'applied_rate' => $resolvedRate,
                'resolved_rate' => null,
                'rate_override_reason' => null,
            ];
        }

        /*
         * A typed figure equal to the resolved one is not an override. Treating it as
         * one would demand the permission from anyone who retyped the number the form
         * had already shown them.
         */
        if ($resolvedRate !== null && bccomp($typed, $resolvedRate, Quantity::MONEY_SCALE) === 0) {
            return [
                'applied_rate' => $typed,
                'resolved_rate' => null,
                'rate_override_reason' => null,
            ];
        }

        /*
         * Either a departure from a configured rate, or a rate where none is
         * configured. Both are a deliberate decision about money and both need the
         * permission and a stated reason — the second case especially, since "no rate
         * configured" is also how a mistyped buyer looks.
         */
        $this->assertMayOverride();

        return [
            'applied_rate' => $typed,
            // Null when nothing was configured, which is itself the record: there was
            // no rate to depart from.
            'resolved_rate' => $resolvedRate,
            'rate_override_reason' => $this->requireOverrideReason($attributes['rate_override_reason'] ?? null),
        ];
    }

    /** @throws ValidationException */
    private function requireManualRate(?string $rate): string
    {
        if ($rate === null || trim($rate) === '') {
            throw ValidationException::withMessages([
                'unit_rate' => __('buyers.errors.manual_rate_required'),
            ]);
        }

        $money = Quantity::money($rate);

        if (bccomp($money, '0.00', Quantity::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'unit_rate' => __('buyers.errors.rate_positive'),
            ]);
        }

        return $money;
    }

    /** @throws ValidationException */
    private function assertMayOverride(): void
    {
        if (Gate::denies(self::OVERRIDE_PERMISSION)) {
            throw ValidationException::withMessages([
                'unit_rate' => __('buyers.errors.rate_override_not_permitted'),
            ]);
        }
    }

    /** @throws ValidationException */
    private function requireOverrideReason(?string $reason): string
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages([
                'rate_override_reason' => __('buyers.errors.override_reason_required'),
            ]);
        }

        return trim($reason);
    }

    /**
     * Normalises a quality reading, which may legitimately be absent.
     *
     * Fat is required for a Mandali delivery by the Form Request; SNF is optional.
     * Neither reaches the amount.
     */
    private function quality(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Quantity::money($value);
    }

    /**
     * Refuses a buyer whose channel does not match the workflow.
     *
     * The server's own check, not a restatement of the form's. An id in a request
     * proves only that a row exists: `buyers` holds every channel, and a vendor
     * reaching the Mandali workflow would acquire fat readings and a settlement
     * history, while a direct customer reaching the generic form would bypass the
     * eligibility and pause rules that exist for them.
     *
     * @throws ValidationException
     */
    private function assertChannelMatchesSource(Buyer $buyer, SaleSource $source): void
    {
        $matches = match ($source) {
            SaleSource::MandaliDelivery => $buyer->isMandali(),
            SaleSource::VendorSale => $buyer->isVendor(),
            SaleSource::GenericSale => $buyer->isCustomChannel(),
            // The grid owns its own workflow and does not come through here.
            SaleSource::CustomerDailyGrid => false,
        };

        if ($matches) {
            return;
        }

        throw ValidationException::withMessages([
            'buyer_id' => match ($source) {
                SaleSource::MandaliDelivery => __('buyers.errors.not_a_mandali'),
                SaleSource::VendorSale => __('buyers.errors.not_a_vendor'),
                default => __('buyers.errors.not_a_custom_channel', [
                    'channels' => implode(', ', SalesChannel::systemSlugs()),
                ]),
            },
        ]);
    }
}
