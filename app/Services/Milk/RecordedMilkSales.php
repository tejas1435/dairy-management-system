<?php

declare(strict_types=1);

namespace App\Services\Milk;

use App\Contracts\MilkSalesAllocator;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Models\MilkSale;
use App\Models\SalesChannel;
use App\Support\Milk\SalesAllocation;
use App\Support\Quantity;

/**
 * The real sales allocator: milk sold, read from `milk_sales`.
 *
 * Replaces {@see NoMilkSalesRecorded}, which Phase 3 bound while no sale could
 * exist. The reconciliation engine, its result object and the reconciliation screen
 * are unchanged by the swap — that was the point of putting a contract between them
 * (docs/DECISIONS.md D32). This class and one line in `AppServiceProvider` are the
 * whole of it.
 *
 * Only **active** sales count. A cancelled sale stops being allocated milk the
 * moment it is cancelled, which is what lets a mistaken delivery be withdrawn
 * without a compensating record.
 *
 * Channels come from the seeded sales channels rather than a hard-coded list, so a
 * channel an administrator adds appears in the breakdown without a code change. A
 * channel with no sales shows as zero, which is truthful here in a way it was not in
 * Phase 3: the subsystem now exists, so "nothing sold through this channel today" is
 * a real observation rather than a missing feature.
 */
class RecordedMilkSales implements MilkSalesAllocator
{
    /**
     * Channel slugs, memoised per request.
     *
     * @var array<int, string>|null
     */
    private ?array $channels = null;

    public function allocationFor(
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType,
    ): SalesAllocation {
        /*
         * One grouped query per reconciliation unit, joined to the channel for its
         * slug. Summed in SQL and normalised through Quantity, so a busy shift costs
         * the same number of round trips as an empty one and no litre value passes
         * through a float.
         */
        $totals = MilkSale::query()
            ->active()
            ->forShift($farmId, $date, $shift, $milkType)
            ->join('sales_channels', 'sales_channels.id', '=', 'milk_sales.sales_channel_id')
            ->groupBy('sales_channels.slug')
            ->selectRaw('sales_channels.slug as slug, SUM(milk_sales.quantity) as total')
            ->pluck('total', 'slug');

        $byChannel = [];

        foreach ($this->channels() as $slug) {
            $byChannel[$slug] = Quantity::of($totals[$slug] ?? null);
        }

        /*
         * A sale against a channel that has since been removed from the list would
         * otherwise vanish from the breakdown while still counting in the total.
         * Including it keeps the parts equal to the whole.
         */
        foreach ($totals as $slug => $total) {
            if (! array_key_exists($slug, $byChannel)) {
                $byChannel[$slug] = Quantity::of($total);
            }
        }

        return SalesAllocation::fromChannels($byChannel);
    }

    /**
     * The channels the breakdown reports on, in display order.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return $this->channels ??= SalesChannel::query()
            ->ordered()
            ->pluck('slug')
            ->all();
    }
}
