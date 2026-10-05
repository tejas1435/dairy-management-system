<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A business partner.
 *
 * There is no balance or total_contributed column. The partner ledger is
 * derived from contributions and partner-funded allocations, so it cannot drift
 * away from the records it summarises.
 */
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'mobile',
        'email',
        'joining_date',
        'is_active',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<PartnerContribution, $this> */
    public function contributions(): HasMany
    {
        return $this->hasMany(PartnerContribution::class);
    }

    /**
     * Expenses and other payables this partner funded.
     *
     * @return MorphMany<FundingAllocation, $this>
     */
    public function fundingAllocations(): MorphMany
    {
        return $this->morphMany(FundingAllocation::class, 'source');
    }

    /**
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasFinancialHistory(): bool
    {
        return $this->contributions()->exists() || $this->fundingAllocations()->exists();
    }
}
