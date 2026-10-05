<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DateFormat;
use App\Enums\Locale;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'mobile',
        'email',
        'address',
        'currency',
        'timezone',
        'date_format',
        'default_locale',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_format' => DateFormat::class,
            'default_locale' => Locale::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Farm, $this> */
    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Farm, $this> */
    public function activeFarms(): HasMany
    {
        return $this->farms()->where('is_active', true);
    }

    /**
     * The business's primary farm, if one is set.
     *
     * Prefer App\Services\BusinessContext::primaryFarm() in application code so
     * the lookup is resolved once per request and the "exactly one primary"
     * rule stays in one place.
     */
    public function primaryFarm(): ?Farm
    {
        return $this->farms()->where('is_primary', true)->first();
    }

    public function displayName(): string
    {
        return $this->legal_name ?: $this->name;
    }
}
