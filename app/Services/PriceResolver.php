<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MilkType;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Support\ResolvedPrice;

/**
 * Works out which rate applies to a sale.
 *
 * Resolution order, for a given date and milk type (MASTER_SPEC section 17):
 *
 *   1. a buyer-specific rule whose period contains that date;
 *   2. otherwise the business default rule whose period contains that date;
 *   3. otherwise nothing — and "nothing" is returned as an explicit failure.
 *
 * The third case is the important one. Returning zero, or quietly falling back
 * to today's price, would let a sale be saved at a rate nobody chose, and the
 * error would only surface as wrong money weeks later. The caller gets a
 * ResolvedPrice that knows it failed and must decide what to tell the user.
 *
 * Resolution is always **by sale date**, never by today. Re-pricing tomorrow
 * must not change what yesterday cost.
 *
 * `milk_sales` is the caller as of Phase 4, and it stores the answer as a snapshot
 * rather than a reference, so a corrected rule never re-prices a recorded sale.
 */
class PriceResolver
{
    /** @var array<string, ResolvedPrice> */
    private array $memo = [];

    public function resolve(Buyer $buyer, MilkType $milkType, string $date): ResolvedPrice
    {
        $key = implode('|', [$buyer->getKey(), $milkType->value, $date]);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $override = BuyerPriceRule::query()
            ->where('buyer_id', $buyer->getKey())
            ->where('milk_type', $milkType->value)
            ->effectiveOn($date)
            // Newest start date wins if periods were ever allowed to overlap.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($override) {
            return $this->memo[$key] = ResolvedPrice::fromBuyerRule($override);
        }

        $default = $this->businessRule($buyer->business_id, $milkType, $date);

        if ($default) {
            return $this->memo[$key] = ResolvedPrice::fromBusinessRule($default);
        }

        return $this->memo[$key] = ResolvedPrice::missing($milkType, $date);
    }

    /**
     * Resolves every buyer and milk type for one date up front, in two queries.
     *
     * The Customer Daily Entry grid needs a rate for every row it draws, and asking
     * `resolve()` per row is two queries per row — the textbook N+1, and one that
     * grows with the customer list rather than with anything the operator did.
     *
     * This fills the same memo `resolve()` reads, so resolution stays in one place:
     * callers keep calling `resolve()` and simply find the answer already there. A
     * buyer absent from the priming set still resolves normally, one query at a time.
     *
     * @param  iterable<int, Buyer>  $buyers
     */
    public function primeFor(iterable $buyers, string $date): void
    {
        $buyers = collect($buyers);

        if ($buyers->isEmpty()) {
            return;
        }

        $buyerIds = $buyers->map(fn (Buyer $buyer): int => $buyer->getKey())->all();
        $businessIds = $buyers->map(fn (Buyer $buyer): int => (int) $buyer->business_id)->unique()->all();

        /*
         * Newest start date first, so the first row seen for a buyer and milk type
         * is the one `resolve()` would have picked on its own.
         */
        $overrides = [];

        foreach (BuyerPriceRule::query()
            ->whereIn('buyer_id', $buyerIds)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get() as $rule) {
            $overrides[$rule->buyer_id][$rule->milk_type->value] ??= $rule;
        }

        $defaults = [];

        foreach (MilkPriceRule::query()
            ->whereIn('business_id', $businessIds)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get() as $rule) {
            $defaults[$rule->business_id][$rule->milk_type->value] ??= $rule;
        }

        foreach ($buyers as $buyer) {
            foreach (MilkType::cases() as $milkType) {
                $key = implode('|', [$buyer->getKey(), $milkType->value, $date]);

                if (isset($this->memo[$key])) {
                    continue;
                }

                $override = $overrides[$buyer->getKey()][$milkType->value] ?? null;
                $default = $defaults[$buyer->business_id][$milkType->value] ?? null;

                $this->memo[$key] = match (true) {
                    $override !== null => ResolvedPrice::fromBuyerRule($override),
                    $default !== null => ResolvedPrice::fromBusinessRule($default),
                    default => ResolvedPrice::missing($milkType, $date),
                };
            }
        }
    }

    /** The business default rate, ignoring any buyer override. */
    public function resolveDefault(int $businessId, MilkType $milkType, string $date): ResolvedPrice
    {
        $rule = $this->businessRule($businessId, $milkType, $date);

        return $rule
            ? ResolvedPrice::fromBusinessRule($rule)
            : ResolvedPrice::missing($milkType, $date);
    }

    private function businessRule(int $businessId, MilkType $milkType, string $date): ?MilkPriceRule
    {
        return MilkPriceRule::query()
            ->where('business_id', $businessId)
            ->where('milk_type', $milkType->value)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /** Clears the per-request memo, for tests and long-running processes. */
    public function forget(): void
    {
        $this->memo = [];
    }
}
