<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use Database\Factories\MilkPriceRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business default milk price for one milk type over one period.
 *
 * Rows are append-only in spirit: changing the price closes the open period and
 * opens a new one, so a sale dated in a closed period still resolves to the
 * rate that applied then.
 */
class MilkPriceRule extends Model
{
    /** @use HasFactory<MilkPriceRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
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

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Rules whose period contains the given date. An open period has a null
     * effective_to and runs until it is closed.
     *
     * @param  Builder<MilkPriceRule>  $query
     * @return Builder<MilkPriceRule>
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
