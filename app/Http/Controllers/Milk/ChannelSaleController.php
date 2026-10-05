<?php

declare(strict_types=1);

namespace App\Http\Controllers\Milk;

use App\Actions\Milk\CancelMilkSale;
use App\Actions\Milk\RecordChannelSale;
use App\Actions\Milk\UpdateChannelSale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Milk\CancelChannelSaleRequest;
use App\Http\Requests\Milk\StoreChannelSaleRequest;
use App\Http\Requests\Milk\UpdateChannelSaleRequest;
use App\Models\Buyer;
use App\Models\MilkSale;
use App\Services\BusinessContext;
use App\Services\Milk\MilkAvailability;
use App\Services\Milk\MilkSaleSlips;
use App\Services\PriceResolver;
use App\Support\OperationalDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shared surface behind the three Phase 5 sale-entry screens.
 *
 * Mandali deliveries, vendor sales and the generic form are one workflow with three
 * pricing rules, so they share a controller and differ in what each subclass
 * declares. The pricing rules themselves live in {@see RecordChannelSale}, not here:
 * a controller that decided when a rate was an override would be a second place for
 * that decision to live.
 *
 * Thin, like the rest. It resolves the request, delegates, and chooses a response.
 */
abstract class ChannelSaleController extends Controller
{
    public function __construct(
        protected readonly RecordChannelSale $record,
        protected readonly UpdateChannelSale $updateSale,
        protected readonly CancelMilkSale $cancelSale,
        protected readonly MilkAvailability $availability,
        protected readonly PriceResolver $prices,
        protected readonly MilkSaleSlips $slips,
        protected readonly BusinessContext $context,
    ) {}

    abstract protected function source(): SaleSource;

    /** The Blade directory under `resources/views/milk/`. */
    abstract protected function viewPrefix(): string;

    /** The route name prefix, e.g. `milk.mandali-deliveries`. */
    abstract protected function routePrefix(): string;

    /** The buyers this workflow may sell to. */
    abstract protected function buyerQuery();

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'buyer' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'milk_type' => ['nullable', 'in:'.implode(',', MilkType::values())],
            'status' => ['nullable', 'in:active,cancelled'],
        ]);

        /*
         * Server-side pagination with a date range, not the whole history. A farm
         * selling for three years would otherwise put thousands of rows in one DOM.
         */
        $sales = MilkSale::query()
            ->fromSource($this->source())
            ->with(['buyer:id,name', 'creator:id,name'])
            ->when($filters['buyer'] ?? null, fn ($q, $id) => $q->where('buyer_id', $id))
            ->betweenDates($filters['from'] ?? null, $filters['to'] ?? null)
            ->when($filters['milk_type'] ?? null, fn ($q, $type) => $q->where('milk_type', $type))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->active())
            ->when(($filters['status'] ?? null) === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('milk.'.$this->viewPrefix().'.index', $this->viewData([
            'sales' => $sales,
            'filters' => $filters,
            'buyers' => $this->buyerQuery()->orderBy('name')->get(),
        ]));
    }

    public function create(Request $request): View
    {
        $date = OperationalDate::resolve($request->query('date'));

        return view('milk.'.$this->viewPrefix().'.create', $this->viewData([
            'date' => $date,
            /*
             * Pre-selected when the operator arrived from a buyer's own profile, which
             * is the common path. Only a hint for the form: the posted id is resolved
             * through this workflow's buyer query and authorised again on save, so a
             * buyer from another channel in the query string selects nothing.
             */
            'selectedBuyerId' => $request->integer('buyer') ?: null,
            'buyers' => $this->buyerQuery()->where('is_active', true)->orderBy('name')->get(),
            'shifts' => Shift::cases(),
            'milkTypes' => MilkType::cases(),
            'availability' => $this->availabilitySummary($date->toDateString()),
        ]));
    }

    public function store(StoreChannelSaleRequest $request): RedirectResponse
    {
        $buyer = $this->resolveBuyer($request->integer('buyer_id'));

        $this->record->handle(
            buyer: $buyer,
            source: $this->source(),
            date: $request->date('sale_date')->toDateString(),
            shift: Shift::from($request->string('shift')->toString()),
            milkType: MilkType::from($request->string('milk_type')->toString()),
            quantity: $request->string('quantity')->toString(),
            rate: $request->input('unit_rate'),
            attributes: $request->saleAttributes(),
        );

        return redirect()
            ->route($this->routePrefix().'.index')
            ->with('status', __($this->savedMessageKey()));
    }

    public function edit(MilkSale $sale): View
    {
        $this->authorizeSale($sale);

        return view('milk.'.$this->viewPrefix().'.edit', $this->viewData([
            'sale' => $sale,
            'remaining' => $this->availability->remaining(
                $sale->farm_id,
                $sale->sale_date->toDateString(),
                $sale->shift,
                $sale->milk_type,
            ),
        ]));
    }

    public function update(UpdateChannelSaleRequest $request, MilkSale $sale): RedirectResponse
    {
        $this->authorizeSale($sale);

        $this->updateSale->handle($sale, $request->saleChanges());

        return redirect()
            ->route($this->routePrefix().'.index')
            ->with('status', __('buyers.sale.updated'));
    }

    public function cancel(CancelChannelSaleRequest $request, MilkSale $sale): RedirectResponse
    {
        $this->authorizeSale($sale);

        $this->cancelSale->handle($sale, $request->string('cancellation_reason')->toString());

        return redirect()
            ->route($this->routePrefix().'.index')
            ->with('status', __('buyers.sale.cancelled'));
    }

    /**
     * Streams a collection slip to an authorised viewer.
     *
     * The file lives on a private disk with a randomised name, so there is no URL
     * that reaches it and this is the only way in. The original filename is sent as
     * the download name — it is metadata, and nothing resolves a path from it.
     */
    public function slip(MilkSale $sale): StreamedResponse
    {
        $this->authorize('view', $sale->buyer);
        abort_unless($sale->source === $this->source(), 404);
        abort_unless($this->slips->exists($sale), 404);

        return Storage::disk(MilkSaleSlips::DISK)
            ->download($sale->slip_path, $sale->slip_name ?: basename($sale->slip_path));
    }

    /**
     * Refuses a sale from another workflow, and checks the buyer's own permission.
     *
     * Both halves matter. The route permission says the user may work with channel
     * sales; the policy says they may see this buyer; and the source check stops a
     * Mandali delivery being edited through the vendor screen, where the fat fields
     * and the rate rules are different.
     */
    protected function authorizeSale(MilkSale $sale): void
    {
        abort_unless($sale->source === $this->source(), 404);

        $sale->loadMissing('buyer.salesChannel');
        $this->authorize('view', $sale->buyer);
    }

    protected function resolveBuyer(int $id): Buyer
    {
        /*
         * Resolved through the workflow's own buyer query, so an id belonging to
         * another channel does not come back at all. The action checks the channel
         * again — a screen is not an authority — but failing here gives the operator
         * a validation error rather than a domain exception.
         */
        $buyer = $this->buyerQuery()->whereKey($id)->first();

        abort_if($buyer === null, 422);

        $this->authorize('view', $buyer);

        return $buyer;
    }

    /** @return array<string, mixed> */
    protected function viewData(array $data): array
    {
        return array_merge($data, [
            'source' => $this->source(),
            'routePrefix' => $this->routePrefix(),
            'farm' => $this->context->primaryFarm(),
        ]);
    }

    protected function savedMessageKey(): string
    {
        return match ($this->source()) {
            SaleSource::MandaliDelivery => 'buyers.mandali.delivery_saved',
            SaleSource::VendorSale => 'buyers.vendor.sale_saved',
            default => 'buyers.other.sale_saved',
        };
    }

    /**
     * Milk still unallocated for each shift and milk type on a date.
     *
     * Four reads, so the operator can see the ceiling before hitting it. Information
     * only: the server refuses an over-allocating save on its own terms.
     *
     * @return array<string, array<string, array{remaining: string, production_entered: bool}>>
     */
    protected function availabilitySummary(string $date): array
    {
        $farmId = $this->context->primaryFarmId();
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
}
