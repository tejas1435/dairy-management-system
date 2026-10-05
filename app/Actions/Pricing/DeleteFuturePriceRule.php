<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\AuditAction;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a price rule that has not taken effect yet, and reopens the period
 * before it.
 *
 * This exists so a mistyped future price can be withdrawn. It deliberately
 * refuses anything that has already started, because a rule whose period has
 * begun may already have priced a sale, and deleting it would change what the
 * past cost.
 *
 * The only correction available for a rule already in effect is to open a new
 * period after it. History is appended to, never rewritten.
 */
class DeleteFuturePriceRule
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(MilkPriceRule|BuyerPriceRule $rule): void
    {
        $from = Carbon::parse($rule->effective_from)->startOfDay();

        if ($from->lessThanOrEqualTo(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'rule' => __('pricing.errors.cannot_delete_effective'),
            ]);
        }

        DB::transaction(function () use ($rule, $from): void {
            $previous = $this->siblings($rule)
                ->whereKeyNot($rule->getKey())
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();

            $subject = $this->subject($rule);

            $this->audit->custom(
                AuditAction::Deleted,
                $rule,
                [
                    'rate' => (string) $rule->rate,
                    'effective_from' => $from->toDateString(),
                ],
                [],
                $subject,
            );

            $rule->delete();

            /*
             * Reopen the period this rule had closed, so there is no gap where
             * no price resolves at all.
             */
            if ($previous && $previous->effective_to !== null
                && Carbon::parse($previous->effective_to)->equalTo($from->copy()->subDay())) {
                $previous->forceFill(['effective_to' => null])->save();

                $this->audit->updated(
                    $previous,
                    ['effective_to' => $from->copy()->subDay()->toDateString()],
                    ['effective_to' => null],
                    $subject,
                );
            }
        });
    }

    private function siblings(MilkPriceRule|BuyerPriceRule $rule)
    {
        return $rule instanceof MilkPriceRule
            ? MilkPriceRule::query()
                ->where('business_id', $rule->business_id)
                ->where('milk_type', $rule->milk_type->value)
            : BuyerPriceRule::query()
                ->where('buyer_id', $rule->buyer_id)
                ->where('milk_type', $rule->milk_type->value);
    }

    private function subject(Model $rule): string
    {
        return $rule instanceof MilkPriceRule
            ? ($rule->business?->name ?? '').' — '.$rule->milk_type->label()
            : ($rule->buyer?->name ?? '').' — '.$rule->milk_type->label();
    }
}
