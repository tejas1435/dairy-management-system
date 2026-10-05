<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyers;

use App\Actions\Buyers\CancelBuyerPayment;
use App\Actions\Buyers\RecordBuyerPayment;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Receipts from a Mandali, a vendor or a custom-channel buyer.
 *
 * The **same** `BuyerPayment` and the same two actions Phase 4 built for direct
 * customers. There is no `mandali_payments` table and no `VendorPayment` model: a
 * receipt is a receipt, and a second cash-transaction table would be a second place
 * for the ledger credit, the overpayment rule and the reversal to be implemented —
 * and they would diverge.
 *
 * What differs per channel is the permission, which `BuyerPolicy` resolves through
 * the buyer's family (`mandali.payment.create`, `vendor.payment.create`, and
 * `customer.payment.create` for a custom channel, which is the family custom channels
 * map to under D26). The direct-customer screens keep their own controller because
 * their redirects and views differ; the accounting is identical.
 *
 * Nothing here moves money. The user is recording that money already arrived
 * (MASTER_SPEC section 26) — no gateway, no UPI API, no bank integration.
 */
class BuyerPaymentController extends Controller
{
    public function __construct(
        private readonly RecordBuyerPayment $record,
        private readonly CancelBuyerPayment $cancelAction,
    ) {}

    public function store(Request $request, Buyer $buyer): RedirectResponse
    {
        $this->authorize('recordPayment', $buyer);

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
            'financial_account_id' => ['required', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
            'buyer_settlement_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        /*
         * The overpayment refusal, the settlement cap, the account check and the
         * ledger credit all live in the action. The controller does not re-state any
         * of them: a rule restated in a controller is a rule that only applies when
         * the request comes through that controller.
         */
        $this->record->handle($buyer, $validated);

        return back()->with('status', __('buyers.payment.recorded'));
    }

    public function cancel(Request $request, BuyerPayment $payment): RedirectResponse
    {
        $payment->loadMissing('buyer.salesChannel');

        $this->authorize('cancelPayment', $payment->buyer);

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->cancelAction->handle($payment, $validated['cancellation_reason']);

        return back()->with('status', __('buyers.payment.cancelled'));
    }
}
