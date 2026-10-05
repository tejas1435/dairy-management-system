<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Farms\SetFarmActiveState;
use App\Actions\Farms\SetPrimaryFarm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreFarmRequest;
use App\Http\Requests\Settings\UpdateFarmRequest;
use App\Models\Farm;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Farm settings.
 *
 * V1 operates on one primary farm, which operational screens resolve
 * automatically through BusinessContext. This screen exists so the primary farm
 * can be named and corrected, and so additional locations can be recorded ahead
 * of the multi-farm workflows a later version will add. It is the only place a
 * farm is chosen by hand.
 */
class FarmController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(BusinessContext $context): View
    {
        return view('settings.farms.index', [
            'business' => $context->business(),
            'farms' => $context->business()->farms()->orderByDesc('is_primary')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('settings.farms.create');
    }

    public function store(StoreFarmRequest $request, BusinessContext $context): RedirectResponse
    {
        $validated = $request->validated();

        $farm = DB::transaction(function () use ($validated, $context): Farm {
            $farm = Farm::create([
                'business_id' => $context->business()->id,
                'name' => $validated['name'],
                'code' => $validated['code'],
                'address' => $validated['address'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'is_primary' => false,
            ]);

            $this->audit->created($farm, [
                'name' => $farm->name,
                'code' => $farm->code,
                'is_active' => $farm->is_active,
            ], $farm->label());

            return $farm;
        });

        return redirect()->route('settings.farms.index')
            ->with('status', __('settings.farm.created', ['name' => $farm->name]));
    }

    public function edit(Farm $farm): View
    {
        return view('settings.farms.edit', ['farm' => $farm]);
    }

    public function update(UpdateFarmRequest $request, Farm $farm): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $farm): void {
            $before = $farm->only(['name', 'code', 'address']);

            $farm->fill([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'address' => $validated['address'] ?? null,
            ])->save();

            $this->audit->updated($farm, $before, $farm->only(['name', 'code', 'address']), $farm->label());
        });

        return redirect()->route('settings.farms.index')
            ->with('status', __('settings.farm.updated', ['name' => $farm->name]));
    }

    /** Promotes a farm to primary through the action that keeps exactly one. */
    public function makePrimary(Farm $farm, SetPrimaryFarm $action): RedirectResponse
    {
        $action->handle($farm);

        return back()->with('status', __('settings.farm.primary_changed', ['name' => $farm->name]));
    }

    public function updateStatus(Request $request, Farm $farm, SetFarmActiveState $action): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $action->handle($farm, (bool) $validated['is_active']);

        return back()->with('status', __(
            $validated['is_active'] ? 'settings.farm.activated' : 'settings.farm.deactivated',
            ['name' => $farm->name]
        ));
    }
}
