<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Actions\Pricing\DeleteFuturePriceRule;
use App\Actions\Pricing\SetMilkPrice;
use App\Enums\MilkType;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerPriceRule;
use App\Services\Customers\CustomerEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer-specific price overrides, managed from the customer profile.
 *
 * Deliberately thin. It delegates to the Phase 2 pricing actions and the Phase 2
 * `buyer_price_rules` table — there is **no customer price table**, because a
 * customer is a buyer and buyer overrides already exist with effective dating,
 * forward-only periods and overlap prevention (docs/DECISIONS.md D27).
 *
 * The resolution order is Phase 2's unchanged: a customer override for the sale date,
 * otherwise the business default for that date, otherwise an explicit failure.
 * Setting an override here never rewrites history — it opens a new period and closes
 * the current one.
 */
class CustomerPriceController extends Controller
{
    public function __construct(private readonly CustomerEligibilityService $eligibility) {}

    public function store(Request $request, Buyer $customer, SetMilkPrice $action): RedirectResponse
    {
        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'milk_type' => ['required', Rule::in(MilkType::values())],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999.99'],
            'effective_from' => ['required', 'date'],
        ]);

        $action->forBuyer(
            buyer: $customer,
            milkType: MilkType::from($validated['milk_type']),
            rate: (string) $validated['rate'],
            effectiveFrom: $validated['effective_from'],
        );

        return back()->with('status', __('pricing.price_set'));
    }

    /** Withdraws an override that has not taken effect yet. */
    public function destroy(Buyer $customer, BuyerPriceRule $priceRule, DeleteFuturePriceRule $action): RedirectResponse
    {
        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('update', $customer);

        if ((int) $priceRule->buyer_id !== (int) $customer->getKey()) {
            throw ValidationException::withMessages(['rule' => __('pricing.errors.wrong_buyer')]);
        }

        $action->handle($priceRule);

        return back()->with('status', __('pricing.future_rule_removed'));
    }
}
