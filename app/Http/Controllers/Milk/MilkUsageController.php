<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Actions\Milk\CancelMilkUsage;
use App\Actions\Milk\RecordMilkUsage;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milk\StoreMilkUsageRequest;
use App\Models\MilkUsage;
use App\Services\BusinessContext;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\OperationalDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Internal milk usage: milk consumed rather than sold (MASTER_SPEC section 15).
 *
 * The screen carries the entry form and the day's history together, because the
 * question "how much can I still allocate" is only answerable next to what has
 * already been allocated. The reconciliation context for the selected date is
 * shown for the same reason.
 *
 * There is no update route. A recorded usage has already changed what the shift
 * had left, so it is corrected by cancelling it — with a reason — and entering the
 * right figure. That leaves both in the history, which is what someone auditing a
 * disputed day needs (MASTER_SPEC section 60).
 */
class MilkUsageController extends Controller
{
    public function __construct(private readonly BusinessContext $context) {}

    public function index(Request $request, CalculateMilkReconciliation $reconciliation): View
    {
        $date = OperationalDate::resolve($request->query('date'));
        $farm = $this->context->primaryFarm();

        $usages = MilkUsage::query()
            ->with(['creator:id,name', 'canceller:id,name'])
            ->where('farm_id', $farm->id)
            ->whereDate('usage_date', $date->toDateString())
            ->orderBy('shift')
            ->orderBy('milk_type')
            ->orderByDesc('id')
            ->get();

        return view('milk.usage.index', [
            'farm' => $farm,
            'date' => $date,
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'usages' => $usages,
            'shifts' => Shift::cases(),
            'milkTypes' => MilkType::cases(),
            'usageTypes' => MilkUsageType::cases(),
            // Both shifts, both types: what is available and what is left.
            'reconciliation' => $reconciliation->forDate($farm->id, $date->toDateString()),
        ]);
    }

    public function store(StoreMilkUsageRequest $request, RecordMilkUsage $action): RedirectResponse
    {
        $validated = $request->validated();

        $action->handle(
            date: OperationalDate::resolveString($validated['usage_date']),
            shift: Shift::from($validated['shift']),
            milkType: MilkType::from($validated['milk_type']),
            usageType: MilkUsageType::from($validated['usage_type']),
            quantity: (string) $validated['quantity'],
            notes: $validated['notes'] ?? null,
        );

        return redirect()
            ->route('milk.usage.index', ['date' => OperationalDate::resolveString($validated['usage_date'])])
            ->with('status', __('milk.usage.recorded'));
    }

    public function cancel(Request $request, MilkUsage $usage, CancelMilkUsage $action): RedirectResponse
    {
        $this->assertBelongsToCurrentFarm($usage->farm_id);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'cancellation_reason.required' => __('milk.errors.cancellation_reason_required'),
            'cancellation_reason.min' => __('milk.errors.cancellation_reason_too_short'),
        ]);

        $action->handle($usage, $validated['cancellation_reason']);

        return redirect()
            ->route('milk.usage.index', ['date' => $usage->usage_date->toDateString()])
            ->with('status', __('milk.usage.cancelled'));
    }

    /**
     * An id in a URL proves nothing about which farm the record belongs to.
     *
     * V1 has one farm, so this refuses nothing today; it is here because the day a
     * second farm exists, a route accepting any id becomes a way to read and
     * cancel another farm's records.
     */
    private function assertBelongsToCurrentFarm(int $farmId): void
    {
        if ($farmId !== $this->context->primaryFarmId()) {
            throw ValidationException::withMessages([
                'farm' => __('milk.errors.wrong_farm'),
            ]);
        }
    }
}
