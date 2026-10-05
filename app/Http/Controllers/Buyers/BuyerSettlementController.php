<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyers;

use App\Actions\Settlements\CancelBuyerSettlement;
use App\Actions\Settlements\CreateBuyerSettlement;
use App\Actions\Settlements\FinalizeBuyerSettlement;
use App\Enums\SettlementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buyers\FinalizeSettlementRequest;
use App\Http\Requests\Buyers\StoreSettlementRequest;
use App\Models\Buyer;
use App\Models\BuyerSettlement;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Services\BusinessContext;
use App\Services\Buyers\MandaliSettlementCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mandali settlements (MASTER_SPEC section 23).
 *
 * Nested under the Mandali, because a settlement has no meaning apart from the buyer
 * whose milk it settles. Every action is gated by `BuyerPolicy::manageSettlement()`,
 * which requires both `mandali.settlement.manage` and a buyer that actually is a
 * Mandali — the second half is not redundant: a settlement against a direct customer
 * would be a routing bug, and refusing it is cheaper than finding it in the data.
 *
 * Thin. The arithmetic is in {@see MandaliSettlementCalculator} and the transitions
 * are in the three actions.
 */
class BuyerSettlementController extends Controller
{
    public function __construct(
        private readonly MandaliSettlementCalculator $calculator,
        private readonly CreateBuyerSettlement $create,
        private readonly FinalizeBuyerSettlement $finalize,
        private readonly CancelBuyerSettlement $cancelAction,
        private readonly BusinessContext $context,
    ) {}

    public function index(Request $request, Buyer $mandali): View
    {
        $this->authorize('manageSettlement', $mandali);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SettlementStatus::values())],
        ]);

        return view('mandalis.settlements.index', [
            'mandali' => $mandali,
            /*
             * Filtered by status because the useful questions about a settlement
             * history are "what is still owed" and "what is still a draft", and after
             * three years of monthly periods the answer is two pages down.
             */
            'settlements' => $mandali->settlements()
                ->withCount(['payments' => fn ($q) => $q->where('status', 'active')])
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->orderByDesc('period_start')
                ->paginate(25)
                ->withQueryString(),
            'statuses' => SettlementStatus::cases(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, Buyer $mandali): View
    {
        $this->authorize('manageSettlement', $mandali);

        // Defaults to last month, which is the period somebody opening this screen
        // on the first of the month almost always wants.
        $start = $request->query('from') ?: now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $end = $request->query('to') ?: now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        return view('mandalis.settlements.create', [
            'mandali' => $mandali,
            'periodStart' => $start,
            'periodEnd' => $end,
            // The live figures, so the operator can see what the period contains
            // before opening a settlement over it.
            'preview' => $this->calculator->calculate($mandali, $start, $end),
        ]);
    }

    public function store(StoreSettlementRequest $request, Buyer $mandali): RedirectResponse
    {
        $this->authorize('manageSettlement', $mandali);

        $settlement = $this->create->handle(
            mandali: $mandali,
            periodStart: $request->date('period_start')->toDateString(),
            periodEnd: $request->date('period_end')->toDateString(),
            statementAmount: $request->input('statement_amount'),
            notes: $request->input('notes'),
        );

        return redirect()
            ->route('mandalis.settlements.show', [$mandali, $settlement])
            ->with('status', __('buyers.settlement.created'));
    }

    public function show(Buyer $mandali, BuyerSettlement $settlement): View
    {
        $this->authorize('manageSettlement', $mandali);
        $this->assertBelongsTo($mandali, $settlement);

        $from = $settlement->period_start->toDateString();
        $to = $settlement->period_end->toDateString();

        return view('mandalis.settlements.show', [
            'mandali' => $mandali,
            'settlement' => $settlement,
            'sales' => $this->calculator->salesFor($mandali, $from, $to),
            /*
             * A draft shows live figures, which is safe because a draft has no
             * accounting effect. A finalized settlement shows its own snapshots —
             * recomputing them would change an agreed figure behind the user's back.
             */
            'live' => $settlement->isDraft()
                ? $this->calculator->calculate($mandali, $from, $to)
                : null,
            'payments' => $settlement->payments()
                ->with(['paymentMethod:id,name', 'account:id,name'])
                ->orderByDesc('payment_date')
                ->get(),
            'adjustments' => $settlement->adjustments()->orderByDesc('id')->get(),
            'accounts' => FinancialAccount::query()
                ->where('business_id', $this->context->business()->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function update(StoreSettlementRequest $request, Buyer $mandali, BuyerSettlement $settlement): RedirectResponse
    {
        $this->authorize('manageSettlement', $mandali);
        $this->assertBelongsTo($mandali, $settlement);

        $this->create->updateDraft($settlement, $request->input('statement_amount'), $request->input('notes'));

        return redirect()
            ->route('mandalis.settlements.show', [$mandali, $settlement])
            ->with('status', __('buyers.settlement.updated'));
    }

    public function finalize(FinalizeSettlementRequest $request, Buyer $mandali, BuyerSettlement $settlement): RedirectResponse
    {
        $this->authorize('manageSettlement', $mandali);
        $this->assertBelongsTo($mandali, $settlement);

        $this->finalize->handle($settlement, $request->input('statement_amount'));

        return redirect()
            ->route('mandalis.settlements.show', [$mandali, $settlement])
            ->with('status', __('buyers.settlement.finalized'));
    }

    public function cancel(Request $request, Buyer $mandali, BuyerSettlement $settlement): RedirectResponse
    {
        $this->authorize('manageSettlement', $mandali);
        $this->assertBelongsTo($mandali, $settlement);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->cancelAction->handle($settlement, $validated['cancellation_reason']);

        return redirect()
            ->route('mandalis.settlements.index', $mandali)
            ->with('status', __('buyers.settlement.cancelled'));
    }

    /**
     * Refuses a settlement that belongs to another buyer.
     *
     * Nested route parameters are independent: `/mandalis/5/settlements/9` would
     * otherwise show buyer 5's screen with buyer 7's settlement on it.
     */
    private function assertBelongsTo(Buyer $mandali, BuyerSettlement $settlement): void
    {
        abort_unless((int) $settlement->buyer_id === (int) $mandali->getKey(), 404);
    }
}
