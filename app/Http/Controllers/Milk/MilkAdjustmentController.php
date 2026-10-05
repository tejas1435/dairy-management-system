<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Actions\Milk\CancelMilkAdjustment;
use App\Actions\Milk\RecordMilkAdjustment;
use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milk\StoreMilkAdjustmentRequest;
use App\Models\MilkAdjustment;
use App\Services\BusinessContext;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\OperationalDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Authorised adjustments to the milk available for a shift
 * (MASTER_SPEC section 15).
 *
 * Its own screen, behind its own permission, and presented as the exception it is.
 * The reconciliation context for the date is shown alongside the form so the
 * person can see what is currently recorded — but **no figure is ever pre-filled**.
 * Offering a quantity that would make remaining come out at zero would turn a
 * deliberate statement about what happened into a button somebody clicks, which is
 * the whole failure mode the audit requirement exists to prevent.
 */
class MilkAdjustmentController extends Controller
{
    public function __construct(private readonly BusinessContext $context) {}

    public function index(Request $request, CalculateMilkReconciliation $reconciliation): View
    {
        $date = OperationalDate::resolve($request->query('date'));
        $farm = $this->context->primaryFarm();

        $adjustments = MilkAdjustment::query()
            ->with(['creator:id,name', 'canceller:id,name'])
            ->where('farm_id', $farm->id)
            ->whereDate('adjustment_date', $date->toDateString())
            ->orderBy('shift')
            ->orderBy('milk_type')
            ->orderByDesc('id')
            ->get();

        return view('milk.adjustments.index', [
            'farm' => $farm,
            'date' => $date,
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'adjustments' => $adjustments,
            'shifts' => Shift::cases(),
            'milkTypes' => MilkType::cases(),
            'directions' => AdjustmentDirection::cases(),
            'reconciliation' => $reconciliation->forDate($farm->id, $date->toDateString()),
        ]);
    }

    public function store(StoreMilkAdjustmentRequest $request, RecordMilkAdjustment $action): RedirectResponse
    {
        $validated = $request->validated();
        $date = OperationalDate::resolveString($validated['adjustment_date']);

        $action->handle(
            date: $date,
            shift: Shift::from($validated['shift']),
            milkType: MilkType::from($validated['milk_type']),
            direction: AdjustmentDirection::from($validated['direction']),
            quantity: (string) $validated['quantity'],
            reason: (string) $validated['reason'],
        );

        return redirect()
            ->route('milk.adjustments.index', ['date' => $date])
            ->with('status', __('milk.adjustments.recorded'));
    }

    public function cancel(
        Request $request,
        MilkAdjustment $adjustment,
        CancelMilkAdjustment $action
    ): RedirectResponse {
        $this->assertBelongsToCurrentFarm($adjustment->farm_id);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'cancellation_reason.required' => __('milk.errors.cancellation_reason_required'),
            'cancellation_reason.min' => __('milk.errors.cancellation_reason_too_short'),
        ]);

        $action->handle($adjustment, $validated['cancellation_reason']);

        return redirect()
            ->route('milk.adjustments.index', ['date' => $adjustment->adjustment_date->toDateString()])
            ->with('status', __('milk.adjustments.cancelled'));
    }

    private function assertBelongsToCurrentFarm(int $farmId): void
    {
        if ($farmId !== $this->context->primaryFarmId()) {
            throw ValidationException::withMessages([
                'farm' => __('milk.errors.wrong_farm'),
            ]);
        }
    }
}
