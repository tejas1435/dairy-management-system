<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\MilkType;
use App\Models\Business;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a new price period, closing the previous one.
 *
 * Prices are effective-dated history, not a mutable current value. Changing the
 * price never rewrites a row: the open period is closed the day before the new
 * one starts, and a new row is inserted. A sale dated inside a closed period
 * therefore keeps resolving to the rate that applied then, however many times
 * the price changes afterwards.
 *
 * **Overlap prevention.** MySQL cannot express "no two periods for the same
 * business and milk type may overlap" as a constraint. Two safeguards stand in
 * for it:
 *
 *   - a unique index on (owner, milk type, effective_from), which the database
 *     does enforce, so two rules cannot start on the same day;
 *   - this action, which takes a row lock on the existing rules before deciding,
 *     so two concurrent price changes cannot both read "no later rule exists"
 *     and both insert.
 *
 * New periods may only be opened **forward**. A rule cannot be inserted at or
 * before the latest existing start date, which makes overlap arithmetically
 * impossible rather than merely checked for.
 */
class SetMilkPrice
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Sets the business default rate for a milk type from a given date. */
    public function forBusiness(
        Business $business,
        MilkType $milkType,
        string $rate,
        string $effectiveFrom,
    ): MilkPriceRule {
        return $this->apply(
            query: fn (): Builder => MilkPriceRule::query()
                ->where('business_id', $business->getKey())
                ->where('milk_type', $milkType->value),
            create: fn (array $attributes): MilkPriceRule => MilkPriceRule::create($attributes + [
                'business_id' => $business->getKey(),
                'milk_type' => $milkType->value,
            ]),
            subject: $business->name.' — '.$milkType->label(),
            rate: $rate,
            effectiveFrom: $effectiveFrom,
        );
    }

    /** Sets a buyer-specific override for a milk type from a given date. */
    public function forBuyer(
        Buyer $buyer,
        MilkType $milkType,
        string $rate,
        string $effectiveFrom,
    ): BuyerPriceRule {
        return $this->apply(
            query: fn (): Builder => BuyerPriceRule::query()
                ->where('buyer_id', $buyer->getKey())
                ->where('milk_type', $milkType->value),
            create: fn (array $attributes): BuyerPriceRule => BuyerPriceRule::create($attributes + [
                'buyer_id' => $buyer->getKey(),
                'milk_type' => $milkType->value,
            ]),
            subject: $buyer->name.' — '.$milkType->label(),
            rate: $rate,
            effectiveFrom: $effectiveFrom,
        );
    }

    /**
     * @param  callable():Builder  $query
     * @param  callable(array<string, mixed>):Model  $create
     */
    private function apply(
        callable $query,
        callable $create,
        string $subject,
        string $rate,
        string $effectiveFrom,
    ): Model {
        $this->assertRateIsPositive($rate);

        $from = $this->parseDate($effectiveFrom);

        return DB::transaction(function () use ($query, $create, $subject, $rate, $from): Model {
            /*
             * The lock is the whole point of doing this in a transaction: it
             * stops a second price change reading the same "latest" row and
             * opening a conflicting period beside it.
             */
            $latest = $query()
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latest) {
                $latestFrom = Carbon::parse($latest->effective_from);

                if ($from->lessThanOrEqualTo($latestFrom)) {
                    throw ValidationException::withMessages([
                        'effective_from' => __('pricing.errors.must_be_later', [
                            'date' => $latestFrom->format('d-m-Y'),
                        ]),
                    ]);
                }

                if ($latest->effective_to !== null
                    && Carbon::parse($latest->effective_to)->greaterThanOrEqualTo($from)) {
                    throw ValidationException::withMessages([
                        'effective_from' => __('pricing.errors.overlaps_existing'),
                    ]);
                }

                // Close the open period the day before the new one begins.
                if ($latest->effective_to === null) {
                    $closedTo = $from->copy()->subDay();

                    $latest->forceFill(['effective_to' => $closedTo->toDateString()])->save();

                    $this->audit->updated(
                        $latest,
                        ['effective_to' => null],
                        ['effective_to' => $closedTo->toDateString()],
                        $subject,
                    );
                }
            }

            $rule = $create([
                'rate' => $rate,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($rule, [
                'rate' => $rate,
                'effective_from' => $from->toDateString(),
            ], $subject);

            return $rule;
        });
    }

    private function assertRateIsPositive(string $rate): void
    {
        if (! is_numeric($rate) || bccomp((string) $rate, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'rate' => __('pricing.errors.rate_positive'),
            ]);
        }
    }

    private function parseDate(string $date): Carbon
    {
        try {
            return Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'effective_from' => __('pricing.errors.invalid_date'),
            ]);
        }
    }
}
