<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Actions\Milk\SaveCustomerDailyDeliveries;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milk\SaveCustomerDailyEntryRequest;
use App\Services\BusinessContext;
use App\Services\Milk\CustomerDailyEntryGrid;
use App\Services\Milk\MilkAvailability;
use App\Support\Milk\DailyEntryRow;
use App\Support\OperationalDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Customer Daily Entry (MASTER_SPEC section 19).
 *
 * The most important screen in the product, and deliberately not an "Add Sale"
 * form: the customer list is the page, and the operator fills in quantities down a
 * column rather than picking a customer two hundred times.
 *
 * Thin, like the other controllers here. The grid is assembled by
 * {@see CustomerDailyEntryGrid} and the day is saved by
 * {@see SaveCustomerDailyDeliveries}; this resolves the date, chooses a response,
 * and nothing else. In particular it contains no sale rules — not availability, not
 * pricing, not eligibility — because those already exist and a second copy of them
 * here would be a second copy to keep correct.
 */
class CustomerDailyEntryController extends Controller
{
    public function __construct(
        private readonly CustomerDailyEntryGrid $grid,
        private readonly MilkAvailability $availability,
        private readonly BusinessContext $context,
    ) {}

    public function index(Request $request): View
    {
        $date = OperationalDate::resolve($request->query('date'));
        $farm = $this->context->primaryFarm();

        $rows = $this->grid->rowsFor($date->toDateString(), $farm->id);

        return view('milk.customer-entry.index', [
            'farm' => $farm,
            'date' => $date,
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'today' => now()->toDateString(),
            'shifts' => Shift::cases(),
            'milkTypes' => MilkType::cases(),
            'rows' => $rows,
            'totals' => $this->grid->totalsFor($rows),
            'availability' => $this->availabilitySummary($date->toDateString(), $farm->id),
            'areas' => $this->areasIn($rows),
            'canCreate' => $request->user()?->can(SaveCustomerDailyDeliveries::PERMISSION_CREATE) ?? false,
            'canUpdate' => $request->user()?->can(SaveCustomerDailyDeliveries::PERMISSION_UPDATE) ?? false,
        ]);
    }

    /**
     * Saves the whole day from a JSON body.
     *
     * Returns the authoritative state rather than echoing what was sent: the
     * browser's totals are a preview computed while the operator typed, and the
     * figures it shows afterwards must be the ones the database actually holds.
     */
    public function store(
        SaveCustomerDailyEntryRequest $request,
        SaveCustomerDailyDeliveries $action,
    ): JsonResponse {
        $date = OperationalDate::resolveString($request->validated('date'));
        $farm = $this->context->primaryFarm();

        $summary = $action->handle($date, $request->deliveryRows(), $farm->id);

        $rows = $this->grid->rowsFor($date, $farm->id);

        return response()->json([
            'saved' => true,
            'date' => $date,
            'summary' => $summary,
            'message' => $summary['changed'] === 0
                ? __('milk.customer_entry.no_changes')
                : trans_choice('milk.customer_entry.saved', $summary['changed'], ['count' => $summary['changed']]),
            'rows' => $rows->map(fn (DailyEntryRow $row): array => $row->toClientPayload())->values(),
            'totals' => $this->grid->totalsFor($rows),
            'availability' => $this->availabilitySummary($date, $farm->id),
        ]);
    }

    /**
     * The previous day's quantities, for the browser to stage in the form.
     *
     * A read, and only a read. It writes no sale, no audit record and no receivable
     * — the operator still has to look at what was copied and press Save Day, which
     * is exactly what MASTER_SPEC section 19 means by "only when the user explicitly
     * clicks it". Nothing calls this on page load.
     */
    public function copyPrevious(Request $request): JsonResponse
    {
        $date = OperationalDate::resolve($request->query('date'));
        $farm = $this->context->primaryFarm();

        $rows = $this->grid->rowsFor($date->toDateString(), $farm->id);

        return response()->json([
            'date' => $date->toDateString(),
            'source_date' => $date->copy()->subDay()->toDateString(),
            'quantities' => $this->grid->previousDayQuantities($date->toDateString(), $rows, $farm->id),
        ]);
    }

    /**
     * Milk still unallocated for each shift and milk type.
     *
     * Four reconciliation reads, regardless of how many customers the grid draws, so
     * the operator can see the ceiling before they hit it. The figures are
     * information: the server still refuses an over-allocating save on its own
     * terms, and the browser is never allowed to decide.
     *
     * @return array<string, array<string, array{remaining: string, production_entered: bool}>>
     */
    private function availabilitySummary(string $date, int $farmId): array
    {
        $summary = [];

        foreach (Shift::cases() as $shift) {
            foreach (MilkType::cases() as $milkType) {
                $result = $this->availability->reconciliationFor($farmId, $date, $shift, $milkType);

                $summary[$shift->value][$milkType->value] = [
                    'remaining' => $result->remaining,
                    'production_entered' => $result->productionEntered,
                ];
            }
        }

        return $summary;
    }

    /**
     * The areas present in the grid, for the area filter.
     *
     * Taken from the rows already loaded rather than queried, and omitted entirely
     * when there is nothing to choose between.
     *
     * @param  Collection<int, DailyEntryRow>  $rows
     * @return array<int, string>
     */
    private function areasIn(Collection $rows): array
    {
        return $rows
            ->map(fn (DailyEntryRow $row): ?string => $row->customer->area)
            ->filter(fn (?string $area): bool => $area !== null && trim($area) !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
