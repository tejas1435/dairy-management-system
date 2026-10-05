<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Actions\Milk\SaveMilkProduction;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milk\SaveProductionRequest;
use App\Models\MilkProduction;
use App\Services\BusinessContext;
use App\Support\OperationalDate;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Daily production entry (MASTER_SPEC section 14).
 *
 * One screen per date, showing the matrix the specification asks for: milk types
 * down, shifts across. The table underneath holds one row per shift, so the screen
 * reads up to two records and saves up to two.
 *
 * The farm is resolved from BusinessContext and never asked for. V1 runs one farm
 * and every operational record already carries `farm_id`, so adding a selector
 * later changes this controller and nothing else.
 */
class ProductionController extends Controller
{
    public function __construct(private readonly BusinessContext $context) {}

    public function index(Request $request): View
    {
        $date = OperationalDate::resolve($request->query('date'));
        $farm = $this->context->primaryFarm();

        /** @var array<string, MilkProduction|null> $records */
        $records = [];

        foreach (Shift::cases() as $shift) {
            $records[$shift->value] = MilkProduction::query()
                ->with(['creator:id,name', 'updater:id,name'])
                ->forShift($farm->id, $date->toDateString(), $shift)
                ->first();
        }

        return view('milk.production.index', [
            'farm' => $farm,
            'date' => $date,
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'today' => now()->toDateString(),
            'shifts' => Shift::cases(),
            'milkTypes' => MilkType::cases(),
            'records' => $records,
            // Column totals for the matrix, computed here rather than in Blade.
            'shiftTotals' => $this->shiftTotals($records),
            'typeTotals' => $this->typeTotals($records),
            'grandTotal' => $this->grandTotal($records),
        ]);
    }

    public function store(SaveProductionRequest $request, SaveMilkProduction $action): RedirectResponse
    {
        $date = OperationalDate::resolve($request->validated('production_date'));

        $action->forDay(
            date: $date->toDateString(),
            shifts: $request->shiftRows(),
        );

        return redirect()
            ->route('milk.production.index', ['date' => $date->toDateString()])
            ->with('status', __('milk.production.saved'));
    }

    /**
     * Total produced in each shift, across both milk types.
     *
     * A shift with no record contributes null rather than zero, so the view can
     * show "not entered" instead of a figure nobody stated.
     *
     * @param  array<string, MilkProduction|null>  $records
     * @return array<string, string|null>
     */
    private function shiftTotals(array $records): array
    {
        $totals = [];

        foreach ($records as $shift => $record) {
            $totals[$shift] = $record?->totalQuantity();
        }

        return $totals;
    }

    /**
     * Total of each milk type across the day's shifts.
     *
     * @param  array<string, MilkProduction|null>  $records
     * @return array<string, string>
     */
    private function typeTotals(array $records): array
    {
        $totals = [];

        foreach (MilkType::cases() as $milkType) {
            $totals[$milkType->value] = Quantity::sum(
                array_map(
                    fn (?MilkProduction $record): string => $record?->quantityFor($milkType) ?? Quantity::ZERO,
                    $records,
                )
            );
        }

        return $totals;
    }

    /** @param  array<string, MilkProduction|null>  $records */
    private function grandTotal(array $records): string
    {
        return Quantity::sum(array_map(
            fn (?MilkProduction $record): string => $record?->totalQuantity() ?? Quantity::ZERO,
            $records,
        ));
    }
}
