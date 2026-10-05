<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use Database\Factories\BuyerPriceRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer-specific milk price override for one milk type over one period.
 *
 * Absence is normal: a buyer with no rule for a date falls back to the business
 * default.
 */
class BuyerPriceRule extends Model
{
    /** @use HasFactory<BuyerPriceRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'milk_type',
        'rate',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'milk_type' => MilkType::class,
            'rate' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * @param  Builder<BuyerPriceRule>  $query
     * @return Builder<BuyerPriceRule>
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }
}
