<?php

declare(strict_types=1);

namespace App\Actions\Farms;

use App\Models\Farm;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates or deactivates a farm.
 *
 * The primary farm cannot be deactivated: operational workflows resolve it
 * automatically, so a deactivated primary would leave milk production and sales
 * with no farm to attach to. Promote another farm first.
 */
class SetFarmActiveState
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Farm $farm, bool $active): Farm
    {
        if (! $active && $farm->is_primary) {
            throw ValidationException::withMessages([
                'is_active' => __('settings.farm.errors.cannot_deactivate_primary'),
            ]);
        }

        return DB::transaction(function () use ($farm, $active): Farm {
            $farm->forceFill(['is_active' => $active])->save();

            $this->audit->statusChanged($farm, $active, $farm->label());

            $this->context->forget();

            return $farm;
        });
    }
}
