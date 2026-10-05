<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SalesChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination milk is sold to.
 *
 * The three seeded channels are "system" because later phases branch on their
 * slugs to pick a specialised workflow. Their slugs are therefore fixed, while
 * their display names stay editable and translatable. Custom channels have no
 * specialised behaviour and use the generic sale entry.
 */
class SalesChannel extends Model
{
    /** @use HasFactory<SalesChannelFactory> */
    use HasFactory;

    public const MANDALI = 'mandali';

    public const VENDOR = 'vendor';

    public const DIRECT_CUSTOMER = 'direct_customer';

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'is_system',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<int, string> */
    public static function systemSlugs(): array
    {
        return [self::MANDALI, self::VENDOR, self::DIRECT_CUSTOMER];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<Buyer, $this> */
    public function buyers(): HasMany
    {
        return $this->hasMany(Buyer::class);
    }

    /**
     * @param  Builder<SalesChannel>  $query
     * @return Builder<SalesChannel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<SalesChannel>  $query
     * @return Builder<SalesChannel>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isSystemChannel(): bool
    {
        return $this->is_system && in_array($this->slug, self::systemSlugs(), true);
    }

    public function hasBuyers(): bool
    {
        return $this->buyers()->exists();
    }
}
