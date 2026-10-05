<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Pricing\DeleteFuturePriceRule;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Models\SalesChannel;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\PriceResolver;
use App\Support\BuyerPermissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The buyer master.
 *
 * One table serves Mandalis, vendors, direct customers and custom channels, but
 * the specification gives each its own permission family. BuyerPolicy resolves
 * which permission applies from the row's channel, so the rule lives in one
 * place rather than as conditionals scattered through this controller.
 *
 * Phase 2 covers identity and pricing overrides only. Milk preferences, pause
 * periods, deliveries, ledgers and payments belong to Phases 4 and 5.
 */
class BuyerController extends Controller
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Buyer::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
            'area' => ['nullable', 'string', 'max:255'],
        ]);

        $buyers = Buyer::query()
            ->with('salesChannel:id,name,slug')
            ->where('business_id', $this->context->business()->id)
            ->where(fn ($q) => $this->restrictToVisibleChannels($q, $request))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('mobile', 'like', "%{$s}%")))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('sales_channel_id', $c))
            ->when($filters['area'] ?? null, fn ($q, $a) => $q->where('area', 'like', "%{$a}%"))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('buyers.index', [
            'buyers' => $buyers,
            'filters' => $filters,
            'channels' => $this->channels(),
        ]);
    }

    /**
     * The create form, optionally with a channel pre-selected.
     *
     * Phase 5's Mandali and vendor lists link here with `?channel=` rather than
     * carrying their own create form: the fields, the validation and the
     * channel-resolved authorisation are all already right here, and a second copy
     * would be a second place to keep them right. The pre-selection is a
     * convenience — the posted channel is still authorised on its own terms in
     * `store()`.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Buyer::class);

        return view('buyers.create', [
            'channels' => $this->channels()->where('is_active', true),
            'selectedChannel' => $request->integer('channel') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBuyer($request);

        $channel = SalesChannel::query()->findOrFail($validated['sales_channel_id']);
        $this->assertChannelBelongsToBusiness($channel);

        // Authorised against the channel being created in, not a generic check.
        $this->authorize('create', [Buyer::class, $channel]);

        $buyer = DB::transaction(function () use ($validated, $channel): Buyer {
            $buyer = Buyer::create($validated + [
                'business_id' => $this->context->business()->id,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($buyer, [
                'name' => $buyer->name,
                'sales_channel_id' => $channel->getKey(),
                'mobile' => $buyer->mobile,
                'area' => $buyer->area,
            ]);

            return $buyer;
        });

        return redirect()->route('buyers.index')
            ->with('status', __('buyers.created', ['name' => $buyer->name]));
    }

    public function show(Buyer $buyer, PriceResolver $resolver): View
    {
        $this->authorize('view', $buyer);

        $today = now()->toDateString();
        $resolved = [];
        $history = [];

        foreach (MilkType::cases() as $type) {
            $resolved[$type->value] = $resolver->resolve($buyer, $type, $today);
            $history[$type->value] = $buyer->priceRules()
                ->where('milk_type', $type->value)
                ->orderByDesc('effective_from')
                ->get();
        }

        return view('buyers.show', [
            'buyer' => $buyer->load('salesChannel'),
            'milkTypes' => MilkType::cases(),
            'resolved' => $resolved,
            'history' => $history,
            'today' => $today,
        ]);
    }

    public function edit(Buyer $buyer): View
    {
        $this->authorize('update', $buyer);

        return view('buyers.edit', [
            'buyer' => $buyer,
            'channels' => $this->channels(),
        ]);
    }

    public function update(Request $request, Buyer $buyer): RedirectResponse
    {
        $this->authorize('update', $buyer);

        $validated = $this->validateBuyer($request);

        $channel = SalesChannel::query()->findOrFail($validated['sales_channel_id']);
        $this->assertChannelBelongsToBusiness($channel);

        /*
         * Moving a buyer to another channel changes which permission governs
         * it, so the user must be allowed to create in the destination too.
         * Otherwise someone with only vendor rights could move a buyer into
         * Mandali and keep editing it.
         */
        if ((int) $channel->getKey() !== (int) $buyer->sales_channel_id) {
            $this->authorize('create', [Buyer::class, $channel]);
        }

        DB::transaction(function () use ($validated, $buyer): void {
            $tracked = ['name', 'sales_channel_id', 'mobile', 'email', 'area', 'payment_cycle', 'address'];
            $before = $buyer->only($tracked);

            $buyer->fill($validated)->save();

            $this->audit->updated($buyer, $before, $buyer->only($tracked));
        });

        return redirect()->route('buyers.index')
            ->with('status', __('buyers.updated', ['name' => $buyer->name]));
    }

    public function updateStatus(Request $request, Buyer $buyer): RedirectResponse
    {
        $this->authorize('update', $buyer);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        DB::transaction(function () use ($buyer, $active): void {
            $buyer->forceFill(['is_active' => $active])->save();
            $this->audit->statusChanged($buyer, $active);
        });

        return back()->with('status', __(
            $active ? 'buyers.activated' : 'buyers.deactivated',
            ['name' => $buyer->name]
        ));
    }

    /** Opens a buyer-specific price period. */
    public function storePrice(Request $request, Buyer $buyer, SetMilkPrice $action): RedirectResponse
    {
        $this->authorize('update', $buyer);

        $validated = $request->validate([
            'milk_type' => ['required', Rule::in(MilkType::values())],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999.99'],
            'effective_from' => ['required', 'date'],
        ]);

        $action->forBuyer(
            buyer: $buyer,
            milkType: MilkType::from($validated['milk_type']),
            rate: (string) $validated['rate'],
            effectiveFrom: $validated['effective_from'],
        );

        return back()->with('status', __('pricing.price_set'));
    }

    public function destroyPrice(Buyer $buyer, BuyerPriceRule $priceRule, DeleteFuturePriceRule $action): RedirectResponse
    {
        $this->authorize('update', $buyer);

        if ((int) $priceRule->buyer_id !== (int) $buyer->getKey()) {
            throw ValidationException::withMessages(['rule' => __('pricing.errors.wrong_buyer')]);
        }

        $action->handle($priceRule);

        return back()->with('status', __('pricing.future_rule_removed'));
    }

    /** @return array<string, mixed> */
    private function validateBuyer(Request $request): array
    {
        return $request->validate([
            'sales_channel_id' => ['required', 'integer', Rule::exists('sales_channels', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'area' => ['nullable', 'string', 'max:255'],
            'payment_cycle' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Narrows the list to channels the user may view, so the page never shows
     * rows the server would refuse to open.
     */
    private function restrictToVisibleChannels($query, Request $request): void
    {
        $visibleSlugs = BuyerPermissions::visibleFamiliesFor($request->user());
        $canSeeCustom = BuyerPermissions::canSeeCustomChannels($request->user());

        $query->whereHas('salesChannel', function ($q) use ($visibleSlugs, $canSeeCustom): void {
            $q->whereIn('slug', $visibleSlugs);

            if ($canSeeCustom) {
                $q->orWhereNotIn('slug', SalesChannel::systemSlugs());
            }
        });
    }

    private function channels()
    {
        return SalesChannel::query()
            ->where('business_id', $this->context->business()->id)
            ->ordered()
            ->get();
    }

    private function assertChannelBelongsToBusiness(SalesChannel $channel): void
    {
        if ((int) $channel->business_id !== (int) $this->context->business()->id) {
            throw ValidationException::withMessages([
                'sales_channel_id' => __('buyers.errors.wrong_business'),
            ]);
        }
    }
}
