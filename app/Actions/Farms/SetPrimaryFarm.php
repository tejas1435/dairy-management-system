<?php

declare(strict_types=1);

namespace App\Actions\Farms;

use App\Enums\AuditAction;
use App\Models\Farm;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves the "primary" flag to a given farm, leaving exactly one primary farm
 * for the business.
 *
 * The database also guards this with a unique index on a generated column, so
 * the demotion must happen before the promotion inside one transaction. If the
 * order were reversed the index would reject the write, which is the behaviour
 * we want: a bug here fails loudly instead of leaving two primary farms.
 */
class SetPrimaryFarm
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Farm $farm): Farm
    {
        if (! $farm->is_active) {
            throw ValidationException::withMessages([
                'farm' => __('settings.farm.errors.primary_must_be_active'),
            ]);
        }

        if ($farm->is_primary) {
            return $farm;
        }

        return DB::transaction(function () use ($farm): Farm {
            $previous = Farm::query()
                ->where('business_id', $farm->business_id)
                ->where('is_primary', true)
                ->whereKeyNot($farm->getKey())
                ->first();

            Farm::query()
                ->where('business_id', $farm->business_id)
                ->where('is_primary', true)
                ->whereKeyNot($farm->getKey())
                ->update(['is_primary' => false]);

            $farm->forceFill(['is_primary' => true])->save();

            // Which farm operational records attach to by default is a
            // consequential change, so it is audited in the same transaction.
            $this->audit->custom(
                AuditAction::PrimaryChanged,
                $farm,
                ['primary_farm' => $previous?->label()],
                ['primary_farm' => $farm->label()],
                $farm->label(),
            );

            $this->context->forget();

            return $farm->refresh();
        });
    }
}
