<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FarmFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Farm extends Model
{
    /** @use HasFactory<FarmFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'address',
        'is_primary',
        'is_active',
    ];

    /**
     * primary_farm_lock is a database-generated column that enforces one
     * primary farm per business. It is never written from PHP.
     */
    protected $guarded = [
        'primary_farm_lock',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @param  Builder<Farm>  $query
     * @return Builder<Farm>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Farm>  $query
     * @return Builder<Farm>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
