<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Services\Customers\CustomerEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Money received from a direct customer, recorded from their profile.
 *
 * Validation here is shape only. Whether the amount exceeds what the customer owes is
 * decided by the action inside its transaction, after a lock — a Form Request runs
 * before the transaction opens, so an overpayment check made here could be true when
 * it is asked and false by the time the row is written.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(private readonly CustomerEligibilityService $eligibility) {}

    public function store(Request $request, Buyer $customer, RecordBuyerPayment $action): RedirectResponse
    {
        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('recordPayment', $customer);

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'amount' => __('customers.fields.amount'),
            'financial_account_id' => __('customers.fields.received_into'),
            'payment_method_id' => __('customers.fields.payment_method'),
        ]);

        $action->handle($customer, $validated);

        return back()->with('status', __('customers.payments.recorded'));
    }

    public function cancel(Request $request, BuyerPayment $payment, CancelBuyerPayment $action): RedirectResponse
    {
        $customer = $payment->buyer;

        $this->eligibility->assertDirectCustomer($customer);
        $this->authorize('cancelPayment', $customer);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'cancellation_reason.required' => __('customers.errors.cancellation_reason_required'),
            'cancellation_reason.min' => __('customers.errors.cancellation_reason_too_short'),
        ]);

        $action->handle($payment, $validated['cancellation_reason']);

        return back()->with('status', __('customers.payments.cancelled'));
    }
}
