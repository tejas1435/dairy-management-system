<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\SaveDirectCustomer;
use App\Enums\MilkType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\SaveCustomerRequest;
use App\Models\Buyer;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Services\BusinessContext;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Customers\CustomerEligibilityService;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Customers\CustomerPauseService;
use App\Services\PriceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Direct customers: the people milk is delivered to daily.
 *
 * A dedicated screen rather than a variant of the buyer master, because a customer
 * needs milk preferences, a delivery note, a pause schedule, a ledger and an
 * outstanding balance, and none of those mean anything for a Mandali. The generic
 * buyer master still exists and still owns the channel itself.
 *
 * **Every action resolves the record through the direct-customer channel first.**
 * `buyers` also holds Mandalis and vendors, so an id in a URL proves only that a row
 * exists. Authorisation is separate and comes from BuyerPolicy, which maps the
 * record's channel to its permission family — for these rows, `customer.*`.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly CustomerEligibilityService $eligibility,
        private readonly CustomerPauseService $pauses,
        private readonly BuyerOutstandingService $outstanding,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Buyer::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,archived'],
            'milk_type' => ['nullable', 'string'],
            'paused' => ['nullable', 'in:1'],
        ]);

        $today = now()->toDateString();

        $customers = Buyer::query()
            ->with(['preferences'])
            ->where('business_id', $this->context->business()->getKey())
            ->directCustomers()
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('mobile', 'like', "%{$s}%")))
            ->when($filters['area'] ?? null, fn ($q, $a) => $q->where('area', 'like', "%{$a}%"))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'archived', fn ($q) => $q->where('is_active', false))
            ->when($filters['milk_type'] ?? null, fn ($q, $type) => $q->whereHas(
                'preferences',
                fn ($q) => $q->where('milk_type', $type)->where('is_active', true)
            ))
            ->orderBy('area')
            ->orderBy('name')
            // Server-side pagination: the customer master is a normal list. The
            // Pass 2 daily entry grid is the deliberate exception and loads a whole
            // date at once.
            ->paginate(25)
            ->withQueryString();

        $ids = $customers->getCollection()->pluck('id')->all();

        return view('customers.index', [
            'customers' => $customers,
            'filters' => $filters,
            'milkTypes' => MilkType::cases(),
            'today' => $today,
            // Two grouped queries for the whole page rather than one pair per row.
            'outstanding' => $this->outstanding->outstandingForMany($ids),
            'pausedToday' => $this->pauses->pausedMapFor($ids, $today),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', [Buyer::class, $this->eligibility->channel()]);

        return view('customers.create', [
            'milkTypes' => MilkType::cases(),
        ]);
    }

    public function store(SaveCustomerRequest $request, SaveDirectCustomer $action): RedirectResponse
    {
        // Authorised against the direct-customer channel, which is also the channel
        // the action will assign. A posted channel id is never consulted.
        $this->authorize('create', [Buyer::class, $this->eligibility->channel()]);

        $customer = $action->create($request->validated(), $request->preferenceRows());

        return redirect()->route('customers.show', $customer)
            ->with('status', __('customers.created', ['name' => $customer->name]));
    }

    public function show(Request $request, Buyer $customer, PriceResolver $prices, CustomerLedgerService $ledger): View
    {
        $this->authorizeCustomer($customer, 'view');

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        // Defaults to the current month, which is the period a customer statement is
        // almost always about.
        $month = $filters['month'] ?? now()->format('Y-m');
        [$year, $monthNumber] = array_map('intval', explode('-', $month));

        $from = $filters['from'] ?? sprintf('%04d-%02d-01', $year, $monthNumber);
        $to = $filters['to'] ?? date('Y-m-t', strtotime($from));

        $today = now()->toDateString();

        $priceRules = [];
        $resolved = [];

        foreach (MilkType::cases() as $milkType) {
            $resolved[$milkType->value] = $prices->resolve($customer, $milkType, $today);
            $priceRules[$milkType->value] = $customer->priceRules()
                ->where('milk_type', $milkType->value)
                ->orderByDesc('effective_from')
                ->get();
        }

        return view('customers.show', [
            'customer' => $customer->load(['salesChannel:id,name,slug', 'preferences']),
            'milkTypes' => MilkType::cases(),
            'eligibility' => $this->eligibility->for($customer, $today),
            'pauses' => $this->pauses->timelineFor($customer, $today),
            'outstanding' => $this->outstanding->breakdownFor($customer),
            'statement' => $ledger->statement($customer, $from, $to),
            'summary' => $ledger->monthlySummary($customer, $year, $monthNumber),
            'milkByType' => $ledger->milkByType($customer, $from, $to),
            'payments' => $customer->payments()
                ->with(['paymentMethod:id,name', 'account:id,name', 'creator:id,name', 'canceller:id,name'])
                ->orderByDesc('payment_date')->orderByDesc('id')
                ->limit(25)->get(),
            'accounts' => $this->activeAccounts(),
            'paymentMethods' => $this->activePaymentMethods(),
            'resolved' => $resolved,
            'priceRules' => $priceRules,
            'today' => $today,
            'from' => $from,
            'to' => $to,
            'month' => $month,
        ]);
    }

    public function edit(Buyer $customer): View
    {
        $this->authorizeCustomer($customer, 'update');

        return view('customers.edit', [
            'customer' => $customer->load('preferences'),
            'milkTypes' => MilkType::cases(),
        ]);
    }

    public function update(SaveCustomerRequest $request, Buyer $customer, SaveDirectCustomer $action): RedirectResponse
    {
        $this->authorizeCustomer($customer, 'update');

        $action->update($customer, $request->validated(), $request->preferenceRows());

        return redirect()->route('customers.show', $customer)
            ->with('status', __('customers.updated', ['name' => $customer->name]));
    }

    /**
     * Archives or restores a customer.
     *
     * Behind `customer.archive` rather than `customer.update`: removing somebody from
     * the delivery round is a different decision from correcting their address, and
     * the specification gives it its own permission.
     */
    public function updateStatus(Request $request, Buyer $customer, SaveDirectCustomer $action): RedirectResponse
    {
        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('archive', $customer);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        $action->setActiveState($customer, $active);

        return back()->with('status', __(
            $active ? 'customers.restored' : 'customers.archived',
            ['name' => $customer->name]
        ));
    }

    /**
     * Resolves the record as a direct customer, then authorises it.
     *
     * In that order on purpose: a Mandali id reaching a customer screen is a routing
     * mistake and should be reported as one, not as a permission failure that sends
     * somebody looking for the wrong problem.
     */
    private function authorizeCustomer(Buyer $customer, string $ability): void
    {
        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize($ability, $customer);
    }

    /** Accounts a payment may be received into. */
    private function activeAccounts()
    {
        return FinancialAccount::query()
            ->where('business_id', $this->context->business()->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
    }

    private function activePaymentMethods()
    {
        return PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);
    }
}
