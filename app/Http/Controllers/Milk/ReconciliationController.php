<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Contracts\MilkSalesAllocator;
use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Services\BusinessContext;
use App\Services\Milk\CalculateMilkReconciliation;
use App\Support\OperationalDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The milk reconciliation screen (MASTER_SPEC section 15).
 *
 * Every figure comes from the reconciliation engine; this controller resolves the
 * date and hands over. No arithmetic happens here and none happens in the view.
 *
 * The screen defaults to showing the whole day — both shifts, both milk types —
 * because that is how a day is actually checked. Filters narrow it to one shift or
 * one milk type when a specific question is being asked.
 */
class ReconciliationController extends Controller
{
    public function __construct(private readonly BusinessContext $context) {}

    public function __invoke(
        Request $request,
        CalculateMilkReconciliation $reconciliation,
        MilkSalesAllocator $sales,
    ): View {
        $date = OperationalDate::resolve($request->query('date'));
        $farm = $this->context->primaryFarm();

        $shift = $this->filterEnum($request->query('shift'), Shift::class);
        $milkType = $this->filterEnum($request->query('milk_type'), MilkType::class);

        $shifts = $shift ? [$shift] : Shift::cases();
        $milkTypes = $milkType ? [$milkType] : MilkType::cases();

        $results = [];

        foreach ($shifts as $eachShift) {
            foreach ($milkTypes as $eachType) {
                $results[$eachShift->value][$eachType->value] = $reconciliation->forShift(
                    $farm->id,
                    $date->toDateString(),
                    $eachShift,
                    $eachType,
                );
            }
        }

        return view('milk.reconciliation.index', [
            'farm' => $farm,
            'date' => $date,
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'results' => $results,
            'shifts' => $shifts,
            'milkTypes' => $milkTypes,
            'allShifts' => Shift::cases(),
            'allMilkTypes' => MilkType::cases(),
            'selectedShift' => $shift,
            'selectedMilkType' => $milkType,
            'usageTypes' => MilkUsageType::cases(),
            /*
             * The channels the sales allocator can report on, and whether a sales
             * subsystem exists at all. In Phase 3 this list is empty and the view
             * says the sale modules are not built yet rather than printing rows of
             * zeroes that would read as "nothing was sold".
             */
            'salesChannels' => $sales->channels(),
        ]);
    }

    /**
     * Resolves an optional enum filter, ignoring anything unrecognised.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function filterEnum(mixed $value, string $enum): ?object
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $enum::tryFrom($value);
    }
}
