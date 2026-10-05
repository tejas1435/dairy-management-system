<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MilkType;
use App\Models\BuyerPriceRule;
use App\Models\MilkPriceRule;
use RuntimeException;

/**
 * The outcome of a price lookup.
 *
 * A deliberate value object rather than a nullable float, so "no price is
 * configured" is a state the caller has to handle rather than a zero that
 * silently becomes a free sale. `rate()` throws if called on a failed
 * resolution, which turns a missed check into a crash at the point of the
 * mistake instead of wrong money downstream.
 */
final readonly class ResolvedPrice
{
    private function __construct(
        public bool $found,
        public ?string $rate,
        public ?string $source,
        public ?int $ruleId,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
        public ?MilkType $milkType,
        public ?string $date,
    ) {}

    public static function fromBuyerRule(BuyerPriceRule $rule): self
    {
        return new self(
            found: true,
            rate: (string) $rule->rate,
            source: 'buyer',
            ruleId: $rule->getKey(),
            effectiveFrom: $rule->effective_from?->toDateString(),
            effectiveTo: $rule->effective_to?->toDateString(),
            milkType: $rule->milk_type,
            date: null,
        );
    }

    public static function fromBusinessRule(MilkPriceRule $rule): self
    {
        return new self(
            found: true,
            rate: (string) $rule->rate,
            source: 'business_default',
            ruleId: $rule->getKey(),
            effectiveFrom: $rule->effective_from?->toDateString(),
            effectiveTo: $rule->effective_to?->toDateString(),
            milkType: $rule->milk_type,
            date: null,
        );
    }

    public static function missing(MilkType $milkType, string $date): self
    {
        return new self(
            found: false,
            rate: null,
            source: null,
            ruleId: null,
            effectiveFrom: null,
            effectiveTo: null,
            milkType: $milkType,
            date: $date,
        );
    }

    /**
     * The resolved rate.
     *
     * @throws RuntimeException when no price applies
     */
    public function rate(): string
    {
        if (! $this->found || $this->rate === null) {
            throw new RuntimeException($this->reason());
        }

        return $this->rate;
    }

    public function isFromBuyerOverride(): bool
    {
        return $this->source === 'buyer';
    }

    public function isFromBusinessDefault(): bool
    {
        return $this->source === 'business_default';
    }

    /** A message a sale screen can show verbatim when resolution fails. */
    public function reason(): string
    {
        if ($this->found) {
            return '';
        }

        return __('pricing.errors.not_configured', [
            'type' => $this->milkType?->label() ?? '',
            'date' => $this->date ?? '',
        ]);
    }
}
