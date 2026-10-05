<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyers;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerBalanceAdjustment;
use App\Models\BuyerPayment;
use App\Models\BuyerSettlement;
use App\Models\FinancialAccount;
use App\Models\MilkSale;
use App\Models\PaymentMethod;
use App\Models\SalesChannel;
use App\Services\BusinessContext;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Buyers\BuyerTradeLedger;
use App\Support\BuyerPermissions;
use App\Support\OperationalDate;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The shared surface behind the Mandali and vendor screens.
 *
 * Both are the same two pages over the same table — a channel-scoped list with an
 * outstanding column, and a trade profile with a ledger, receipts and (for a Mandali)
 * settlements. The only differences are the channel slug, the permission family and
 * the wording, so the subclasses declare those three things and nothing else.
 *
 * **Create and edit are deliberately absent.** `BuyerController` has done buyer CRUD
 * across every channel since Phase 2, with the fields, the validation and the
 * channel-resolved authorisation already correct; these lists link to it with the
 * channel pre-selected. A third and fourth copy of that form would be three more
 * places for the validation to drift.
 */
abstract class ChannelBuyerController extends Controller
{
    public function __construct(
        protected readonly BusinessContext $context,
        protected readonly BuyerOutstandingService $outstanding,
        protected readonly BuyerTradeLedger $ledger,
    ) {}

    /**
     * The stable channel slug this controller serves.
     *
     * Null means "every administrator-created channel", which is one screen rather
     * than one per channel: a custom channel is created by the user, so there is no
     * slug to name here and no fixed number of them. The permission family falls back
     * to `customer.*` through {@see BuyerPermissions::familyFor()} for exactly that
     * case (D26), so a null here is not an unguarded screen.
     */
    abstract protected function channelSlug(): ?string;

    /** The Blade directory: `mandalis` or `vendors`. */
    abstract protected function viewPrefix(): string;

    /**
     * The route that records a sale to this channel's buyers.
     *
     * The profile links to it, so the common next action — "this Mandali collected
     * again this morning" — is one step from the account it affects rather than a trip
     * through the sidebar and a buyer dropdown.
     */
    abstract protected function saleRouteName(): string;

    /** The route name prefix, matching the view prefix. */
    abstract protected function routePrefix(): string;

    /**
     * The translation namespace for this channel's wording: `buyers.mandali` or
     * `buyers.vendor`.
     *
     * The shared views title themselves from this, which is why one template can
     * serve both without a conditional per heading.
     */
    abstract protected function translationPrefix(): string;

    public function index(Request $request): View
    {
        /*
         * This channel's own view permission, not the generic one.
         *
         * `viewAny` passes for anyone who can see *some* buyer family, which would
         * let a user holding only `mandali.view` open the vendor list. The lists are
         * channel-scoped, so the authorisation has to be too — resolved through
         * BuyerPermissions, like every other buyer check, rather than hard-coded.
         */
        abort_unless(
            $request->user()?->can(BuyerPermissions::view($this->channelSlug())) ?? false,
            403
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $channel = $this->channel();

        /*
         * Paginated, unlike the Customer Daily Entry grid. That screen loads every
         * customer because the operator fills in a round; this is a master list, and
         * a farm may deal with many vendors over the years.
         */
        $buyers = $this->scopeToChannel(
            Buyer::query()
                ->with('salesChannel:id,name,slug')
                ->where('business_id', $this->context->business()->id)
        )
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($inner) => $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
            ))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view($this->viewPrefix().'.index', [
            'buyers' => $buyers,
            'channel' => $channel,
            'filters' => $filters,
            'outstanding' => $this->outstanding->outstandingForMany($buyers->getCollection()),
            'routePrefix' => $this->routePrefix(),
            'translationPrefix' => $this->translationPrefix(),
        ]);
    }

    /**
     * The trade profile: the ledger, the receipts and the settlement history.
     *
     * The period defaults to the current month, which is the unit a Mandali settles
     * in and the one a vendor's account is usually queried in.
     */
    public function show(Request $request, Buyer $buyer): View
    {
        $this->authorize('view', $buyer);
        $this->assertCorrectChannel($buyer);

        $from = OperationalDate::resolveString($request->query('from') ?: now()->startOfMonth()->toDateString());
        $to = OperationalDate::resolveString($request->query('to') ?: now()->endOfMonth()->toDateString());

        $statement = $this->ledger->statement($buyer, $from, $to);

        return view($this->viewPrefix().'.show', [
            'buyer' => $buyer,
            'from' => $from,
            'to' => $to,
            'statement' => $statement,
            'breakdown' => $this->outstanding->breakdownFor($buyer),
            'sales' => $this->recentSales($buyer),
            'payments' => $this->recentPayments($buyer),
            'adjustments' => $this->recentAdjustments($buyer),
            'settlements' => $settlements = $this->settlements($buyer),
            /*
             * The settlements a receipt may be attached to: finalized, because a draft
             * has agreed no amount and money against one would be money against
             * nothing, and still owing something. Derived from the collection above, so
             * the form costs no further queries.
             */
            'payableSettlements' => $settlements->filter(
                fn (BuyerSettlement $settlement): bool => $settlement->isFinalized()
                    && bccomp($settlement->remainingAmount(), '0.00', Quantity::MONEY_SCALE) > 0
            ),
            'accounts' => FinancialAccount::query()
                ->where('business_id', $this->context->business()->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'saleRoute' => $this->saleRouteName(),
            'routePrefix' => $this->routePrefix(),
            'translationPrefix' => $this->translationPrefix(),
        ]);
    }

    /**
     * The period statement MASTER_SPEC section 23 asks for.
     *
     * A **third** thing, distinct from the two beside it, and worth keeping distinct:
     *
     * | Concept | What it is |
     * | ------- | ---------- |
     * | the **ledger** (on the profile) | the running account history, newest period first, for answering "where does this balance come from" |
     * | a **settlement** | a business record that freezes one period's expected sales and the dairy's statement amount, and posts any difference |
     * | this **statement** | a human-readable report of one period: what was collected, what was corrected, what was received |
     *
     * Merging them would be the mistake. A settlement is a decision with accounting
     * consequences; a statement is a view. They share `BuyerTradeLedger` and
     * `BuyerOutstandingService` — the arithmetic is the same arithmetic — and nothing
     * else.
     *
     * Deliberately **on screen only**. Excel and PDF output is Phase 9, and a report
     * that exists in one format is more useful than one that waits for three.
     */
    public function statement(Request $request, Buyer $buyer): View
    {
        $this->authorize('view', $buyer);
        $this->assertCorrectChannel($buyer);

        /*
         * A month is the unit a Mandali settles in, so `?month=YYYY-MM` is the normal
         * way in and `from`/`to` override it for an awkward period. Both are parsed
         * through OperationalDate, so a malformed parameter shows the current month
         * rather than a validation screen on a navigation link.
         */
        $month = $this->resolveMonth($request->query('month'));

        $from = OperationalDate::resolveString($request->query('from') ?: $month->copy()->startOfMonth()->toDateString());
        $to = OperationalDate::resolveString($request->query('to') ?: $month->copy()->endOfMonth()->toDateString());

        /*
         * With an explicit `from` and no `month`, the period rules: the heading and the
         * arrows follow the dates actually shown rather than today, so a custom period
         * is not labelled with the wrong month.
         */
        if ($request->query('month') === null && $request->query('from')) {
            $month = Carbon::parse($from)->startOfMonth();
        }

        return view('buyers.channel.statement', [
            'buyer' => $buyer,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'previousMonth' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'thisMonth' => now()->format('Y-m'),
            'statement' => $this->ledger->statement($buyer, $from, $to),
            // The whole balance, not just the period's movement: a statement that only
            // showed the month would invite "so we owe nothing" after a paid month.
            'breakdown' => $this->outstanding->breakdownFor($buyer),
            'settlements' => $buyer->isMandali()
                ? $buyer->settlements()->active()->overlapping($from, $to)->orderBy('period_start')->get()
                : collect(),
            'routePrefix' => $this->routePrefix(),
            'translationPrefix' => $this->translationPrefix(),
        ]);
    }

    /**
     * The month a statement is for, defaulting to the current one.
     *
     * The month part is matched as `01`–`12` rather than any two digits, because Carbon
     * rolls `2026-13` forward to January 2027 — so a typo in the address bar would have
     * silently shown a different year's figures under the heading it was given.
     */
    protected function resolveMonth(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($value)) === 1) {
            return Carbon::createFromFormat('Y-m-d', trim($value).'-01')->startOfMonth();
        }

        return now()->startOfMonth();
    }

    /**
     * The channel record, resolved from its stable slug rather than an id.
     *
     * Nullable, deliberately. The sidebar shows this list to anyone holding the
     * channel's view permission, and that is independent of whether the channel row
     * has been seeded — so `firstOrFail()` here turned a missing seed into a 404 on a
     * link the application itself had just drawn. A list with nothing to list is a
     * better answer than a dead end, and it can say what to do about it.
     */
    protected function channel(): ?SalesChannel
    {
        if ($this->channelSlug() === null) {
            return null;
        }

        return SalesChannel::query()
            ->where('business_id', $this->context->business()->id)
            ->where('slug', $this->channelSlug())
            ->first();
    }

    /**
     * Narrows a buyer query to the channel this screen serves.
     *
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    protected function scopeToChannel(Builder $query): Builder
    {
        $slug = $this->channelSlug();

        return $slug === null ? $query->customChannel() : $query->inChannel($slug);
    }

    /**
     * Refuses a buyer from another channel.
     *
     * The policy has already checked the permission family, which for a Mandali and a
     * vendor are different — but a custom channel maps to `customer.*` (D26), so the
     * permission check alone would let one through. An id in a URL proves only that a
     * row exists.
     */
    protected function assertCorrectChannel(Buyer $buyer): void
    {
        $slug = $this->channelSlug();

        abort_unless(
            $slug === null ? $buyer->isCustomChannel() : $buyer->channelSlug() === $slug,
            404
        );
    }

    /** @return Collection<int, MilkSale> */
    protected function recentSales(Buyer $buyer)
    {
        return MilkSale::query()
            ->forBuyer($buyer->getKey())
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /** @return Collection<int, BuyerPayment> */
    protected function recentPayments(Buyer $buyer)
    {
        return BuyerPayment::query()
            ->where('buyer_id', $buyer->getKey())
            ->with(['paymentMethod:id,name', 'account:id,name', 'settlement:id,period_start,period_end'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /** @return Collection<int, BuyerBalanceAdjustment> */
    protected function recentAdjustments(Buyer $buyer)
    {
        return BuyerBalanceAdjustment::query()
            ->where('buyer_id', $buyer->getKey())
            ->with('settlement:id,period_start,period_end')
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * Settlements, which only a Mandali has.
     *
     * Returned empty for a vendor rather than conditionally rendered in two places,
     * so the shared profile view can simply not draw an empty section.
     *
     * @return Collection<int, BuyerSettlement>
     */
    protected function settlements(Buyer $buyer)
    {
        if (! $buyer->isMandali()) {
            return $buyer->settlements()->whereRaw('1 = 0')->get();
        }

        /*
         * The receipts come with them. Every settlement row shows what is still owed,
         * which is derived from its active receipts — so without this the profile
         * issues a query per settlement and gets slower every month the Mandali
         * settles.
         */
        return $buyer->settlements()
            ->with(['payments' => fn ($q) => $q->where('status', TransactionStatus::Active->value)])
            ->withCount(['payments' => fn ($q) => $q->where('status', TransactionStatus::Active->value)])
            ->orderByDesc('period_start')
            ->get();
    }
}
