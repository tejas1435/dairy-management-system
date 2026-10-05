<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Pricing\DeleteFuturePriceRule;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Http\Controllers\Controller;
use App\Models\MilkPriceRule;
use App\Services\BusinessContext;
use App\Services\PriceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Business default milk prices, shown as history rather than as a single
 * editable number.
 *
 * Setting a new price opens a new period; it never edits an old row. That is
 * what keeps a sale dated in September priced at September's rate after the
 * October price is entered.
 */
class MilkPriceController extends Controller
{
    public function __construct(private readonly BusinessContext $context) {}

    public function index(PriceResolver $resolver): View
    {
        $business = $this->context->business();
        $today = now()->toDateString();

        $history = [];
        $current = [];

        foreach (MilkType::cases() as $type) {
            $history[$type->value] = MilkPriceRule::query()
                ->where('business_id', $business->getKey())
                ->where('milk_type', $type->value)
                ->orderByDesc('effective_from')
                ->get();

            $current[$type->value] = $resolver->resolveDefault($business->getKey(), $type, $today);
        }

        return view('settings.milk-prices.index', [
            'milkTypes' => MilkType::cases(),
            'history' => $history,
            'current' => $current,
            'today' => $today,
        ]);
    }

    public function store(Request $request, SetMilkPrice $action): RedirectResponse
    {
        $validated = $request->validate([
            'milk_type' => ['required', Rule::in(MilkType::values())],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999.99'],
            'effective_from' => ['required', 'date'],
        ]);

        $action->forBusiness(
            business: $this->context->business(),
            milkType: MilkType::from($validated['milk_type']),
            rate: (string) $validated['rate'],
            effectiveFrom: $validated['effective_from'],
        );

        return back()->with('status', __('pricing.price_set'));
    }

    /** Withdraws a price that has not taken effect yet. */
    public function destroy(MilkPriceRule $milkPrice, DeleteFuturePriceRule $action): RedirectResponse
    {
        if ((int) $milkPrice->business_id !== (int) $this->context->business()->id) {
            throw ValidationException::withMessages([
                'rule' => __('pricing.errors.wrong_business'),
            ]);
        }

        $action->handle($milkPrice);

        return back()->with('status', __('pricing.future_rule_removed'));
    }
}
