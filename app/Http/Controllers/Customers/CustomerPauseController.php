<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\CancelCustomerPause;
use App\Actions\Customers\CreateCustomerPause;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\CustomerPause;
use App\Services\Customers\CustomerEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customer pause periods, managed from the customer profile.
 *
 * Behind `customer.update`: pausing somebody is changing their delivery arrangement,
 * which is what that permission governs. It is not `customer.archive` — a pause is
 * temporary and reversible, and the customer stays on the round.
 */
class CustomerPauseController extends Controller
{
    public function __construct(private readonly CustomerEligibilityService $eligibility) {}

    public function store(Request $request, Buyer $customer, CreateCustomerPause $action): RedirectResponse
    {
        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            // Null means open-ended. after_or_equal gives a clearer message than the
            // action's own check, which stays as the backstop for direct callers.
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'end_date.after_or_equal' => __('customers.errors.pause_end_before_start'),
        ]);

        $action->handle(
            customer: $customer,
            startDate: $validated['start_date'],
            endDate: $validated['end_date'] ?? null,
            reason: $validated['reason'] ?? null,
        );

        return back()->with('status', __('customers.pauses.created'));
    }

    public function cancel(Request $request, CustomerPause $pause, CancelCustomerPause $action): RedirectResponse
    {
        $customer = $pause->buyer;

        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'cancellation_reason.required' => __('customers.errors.cancellation_reason_required'),
            'cancellation_reason.min' => __('customers.errors.cancellation_reason_too_short'),
        ]);

        $action->handle($pause, $validated['cancellation_reason']);

        return back()->with('status', __('customers.pauses.cancelled'));
    }
}
