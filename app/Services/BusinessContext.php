<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Farm;
use RuntimeException;

/**
 * The single entry point for "which business and which farm are we operating
 * on".
 *
 * V1 runs one business and exposes one primary farm, but operational records
 * still carry farm_id so more locations need no redesign. Resolving that here
 * keeps `Farm::where('is_primary', true)` out of controllers, actions and Blade,
 * so the day a farm selector appears there is one place to change.
 *
 * Registered as a singleton, so each resolution happens once per request.
 */
class BusinessContext
{
    private ?Business $business = null;

    private ?Farm $primaryFarm = null;

    /**
     * The operating business.
     *
     * @throws RuntimeException when the application has not been seeded
     */
    public function business(): Business
    {
        return $this->business ??= Business::query()->oldest('id')->first()
            ?? throw new RuntimeException(
                'No business record exists. Run "php artisan db:seed" to create the foundation data.'
            );
    }

    /** Null-safe variant for views that render before seeding has happened. */
    public function businessOrNull(): ?Business
    {
        try {
            return $this->business();
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * The farm that operational workflows default to.
     *
     * @throws RuntimeException when the business has no primary farm
     */
    public function primaryFarm(): Farm
    {
        return $this->primaryFarm ??= $this->business()->primaryFarm()
            ?? throw new RuntimeException(
                'The business has no primary farm. Run "php artisan db:seed" or set one in Settings.'
            );
    }

    public function primaryFarmOrNull(): ?Farm
    {
        try {
            return $this->primaryFarm();
        } catch (RuntimeException) {
            return null;
        }
    }

    /** Convenience for building operational records. */
    public function primaryFarmId(): int
    {
        return $this->primaryFarm()->id;
    }

    /** Clears memoised state. Used after seeding or a settings change. */
    public function forget(): void
    {
        $this->business = null;
        $this->primaryFarm = null;
    }
}
