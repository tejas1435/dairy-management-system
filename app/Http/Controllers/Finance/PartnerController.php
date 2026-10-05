<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StorePartnerRequest;
use App\Http\Requests\Finance\UpdatePartnerRequest;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\PartnerLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerController extends Controller
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogger $audit,
        private readonly PartnerLedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $partners = Partner::query()
            ->where('business_id', $this->context->business()->id)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('finance.partners.index', [
            'partners' => $partners,
            'filters' => $filters,
            // One aggregate query for the whole page rather than one per row.
            'totals' => $request->user()->can('partner.finance.view')
                ? $this->ledger->totalsFor($partners->getCollection())
                : [],
        ]);
    }

    public function create(): View
    {
        return view('finance.partners.create');
    }

    public function store(StorePartnerRequest $request): RedirectResponse
    {
        $partner = DB::transaction(function () use ($request): Partner {
            $partner = Partner::create($request->validated() + [
                'business_id' => $this->context->business()->id,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($partner, [
                'name' => $partner->name,
                'mobile' => $partner->mobile,
                'joining_date' => $partner->joining_date?->toDateString(),
            ]);

            return $partner;
        });

        return redirect()->route('finance.partners.index')
            ->with('status', __('partners.created', ['name' => $partner->name]));
    }

    /** The partner profile, with the derived ledger when permitted. */
    public function show(Request $request, Partner $partner): View
    {
        $this->assertBelongsToBusiness($partner);

        $period = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $canSeeFinance = $request->user()->can('partner.finance.view');

        return view('finance.partners.show', [
            'partner' => $partner,
            'canSeeFinance' => $canSeeFinance,
            'entries' => $canSeeFinance
                ? $this->ledger->entries($partner, $period['from'] ?? null, $period['to'] ?? null)
                : collect(),
            'total' => $canSeeFinance
                ? $this->ledger->total($partner, $period['from'] ?? null, $period['to'] ?? null)
                : '0.00',
            'period' => $period,
            'accounts' => FinancialAccount::query()
                ->where('business_id', $partner->business_id)
                ->active()
                ->orderBy('name')
                ->get(),
            'paymentMethods' => PaymentMethod::query()->active()->ordered()->get(),
        ]);
    }

    public function edit(Partner $partner): View
    {
        $this->assertBelongsToBusiness($partner);

        return view('finance.partners.edit', ['partner' => $partner]);
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): RedirectResponse
    {
        $this->assertBelongsToBusiness($partner);

        DB::transaction(function () use ($request, $partner): void {
            $before = $partner->only(['name', 'mobile', 'email', 'joining_date', 'notes']);

            $partner->fill($request->validated())->save();

            $this->audit->updated(
                $partner,
                $before,
                $partner->only(['name', 'mobile', 'email', 'joining_date', 'notes'])
            );
        });

        return redirect()->route('finance.partners.index')
            ->with('status', __('partners.updated', ['name' => $partner->name]));
    }

    public function updateStatus(Request $request, Partner $partner): RedirectResponse
    {
        $this->assertBelongsToBusiness($partner);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $validated['is_active'];

        DB::transaction(function () use ($partner, $active): void {
            $partner->forceFill(['is_active' => $active])->save();
            $this->audit->statusChanged($partner, $active);
        });

        return back()->with('status', __(
            $active ? 'partners.activated' : 'partners.deactivated',
            ['name' => $partner->name]
        ));
    }

    private function assertBelongsToBusiness(Partner $partner): void
    {
        if ((int) $partner->business_id !== (int) $this->context->business()->id) {
            throw ValidationException::withMessages([
                'partner' => __('partners.errors.wrong_business'),
            ]);
        }
    }
}
