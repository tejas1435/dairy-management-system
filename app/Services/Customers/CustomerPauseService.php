<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Actions\Customers\CreateCustomerPause;
use App\Models\Buyer;
use App\Models\CustomerPause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Everything that knows how a pause period relates to a date.
 *
 * All of it lives here rather than in a controller or a Blade template, because
 * there are four callers with the same question — the profile screen, the overlap
 * check, the sale refusal, and the Phase 4 Pass 2 grid asking once per customer —
 * and a date-range comparison written four times is a comparison that will
 * eventually disagree with itself. The grid in particular must be able to ask one
 * method and trust the answer.
 *
 * Every comparison is against `DATE` columns through `whereDate`, so no timezone
 * conversion is involved (docs/DECISIONS.md D7).
 */
class CustomerPauseService
{
    /**
     * Whether the customer is paused on a business date.
     *
     * The single question the daily entry grid asks. Cancelled pauses are excluded,
     * so withdrawing a pause immediately makes the customer deliverable again.
     */
    public function isPausedOn(Buyer $buyer, string $date): bool
    {
        return $buyer->pauses()
            ->active()
            ->coveringDate($date)
            ->exists();
    }

    /**
     * The pause covering a date, when there is one.
     *
     * Used where the screen wants to say *why* somebody is paused rather than only
     * that they are.
     */
    public function pauseOn(Buyer $buyer, string $date): ?CustomerPause
    {
        return $buyer->pauses()
            ->active()
            ->coveringDate($date)
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Which of the given customers are paused on a date, keyed by buyer id.
     *
     * One query for the whole grid rather than one per customer. Pass 2 loads
     * several hundred customers for a single date, and the per-customer form of
     * this question would be a textbook N+1.
     *
     * @param  array<int, int>  $buyerIds
     * @return array<int, bool>
     */
    public function pausedMapFor(array $buyerIds, string $date): array
    {
        if ($buyerIds === []) {
            return [];
        }

        $paused = CustomerPause::query()
            ->active()
            ->whereIn('buyer_id', $buyerIds)
            ->coveringDate($date)
            ->pluck('buyer_id')
            ->all();

        $map = [];

        foreach ($buyerIds as $id) {
            $map[$id] = in_array($id, $paused, true);
        }

        return $map;
    }

    /**
     * Pauses grouped for the profile screen: the current one, what is coming, and
     * what has been.
     *
     * @return array{current: ?CustomerPause, upcoming: Collection<int, CustomerPause>, past: Collection<int, CustomerPause>, cancelled: Collection<int, CustomerPause>}
     */
    public function timelineFor(Buyer $buyer, ?string $on = null): array
    {
        $on ??= now()->toDateString();

        $pauses = $buyer->pauses()
            ->with(['creator:id,name', 'canceller:id,name'])
            ->orderByDesc('start_date')
            ->get();

        return [
            'current' => $pauses->first(
                fn (CustomerPause $pause): bool => ! $pause->isCancelled() && $pause->covers($on)
            ),
            'upcoming' => $pauses->filter(
                fn (CustomerPause $pause): bool => ! $pause->isCancelled()
                    && $pause->start_date->gt(Carbon::parse($on))
            )->values(),
            'past' => $pauses->filter(
                fn (CustomerPause $pause): bool => ! $pause->isCancelled()
                    && ! $pause->covers($on)
                    && $pause->start_date->lte(Carbon::parse($on))
            )->values(),
            'cancelled' => $pauses->filter(
                fn (CustomerPause $pause): bool => $pause->isCancelled()
            )->values(),
        ];
    }

    /**
     * Refuses a pause period that overlaps an existing active one.
     *
     * Two overlapping pauses are not wrong so much as **ambiguous**: cancelling one
     * of them would leave the customer paused for reasons nobody stated, and the
     * "is this customer paused" answer stops being traceable to a single record.
     *
     * Adjacent periods are allowed — the 1st to the 5th followed by the 6th to the
     * 10th is two separate decisions and reads as such.
     *
     * Runs inside the caller's transaction against locked rows; see
     * {@see CreateCustomerPause}.
     *
     * @param  int|null  $ignoreId  an existing pause to exclude, when editing one
     *
     * @throws ValidationException
     */
    public function assertNoOverlap(
        Buyer $buyer,
        string $start,
        ?string $end,
        ?int $ignoreId = null,
    ): void {
        $clash = $buyer->pauses()
            ->active()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->overlapping($start, $end)
            ->orderBy('start_date')
            ->first();

        if (! $clash) {
            return;
        }

        throw ValidationException::withMessages([
            'start_date' => __('customers.errors.pause_overlaps', [
                'from' => $clash->start_date->format('d-m-Y'),
                'to' => $clash->end_date?->format('d-m-Y') ?? __('customers.pauses.open_ended'),
            ]),
        ]);
    }

    /**
     * Refuses a period whose end precedes its start.
     *
     * @throws ValidationException
     */
    public function assertOrderedDates(string $start, ?string $end): void
    {
        if ($end === null) {
            return;
        }

        if (Carbon::parse($end)->startOfDay()->lt(Carbon::parse($start)->startOfDay())) {
            throw ValidationException::withMessages([
                'end_date' => __('customers.errors.pause_end_before_start'),
            ]);
        }
    }
}
