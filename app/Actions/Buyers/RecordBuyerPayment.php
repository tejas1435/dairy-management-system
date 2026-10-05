<?php

declare(strict_types=1);

namespace App\Actions\Buyers;

use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\BuyerSettlement;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Buyers\BuyerOutstandingService;
use App\Services\Buyers\SettlementStatusSync;
use App\Services\FinancialLedgerService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money received from a buyer and credits the account it landed in.
 *
 * One transaction covers the payment, the ledger credit and the audit record. If the
 * ledger posting fails the payment does not exist either: a payment without its
 * credit would reduce the customer's outstanding while the cash never appeared in the
 * books, which is the kind of discrepancy nobody finds until a reconciliation.
 *
 * Nothing here moves money. The user is recording that money already arrived
 * (MASTER_SPEC section 26).
 *
 * **The overpayment refusal is the substance.** A payment larger than what the buyer
 * owes is refused rather than silently creating a credit balance. MASTER_SPEC
 * section 27 allows a negative balance only through an explicit authorised workflow,
 * and no such workflow exists — so accepting the payment would invent one by
 * accident, and the customer's ledger would show them in credit for reasons nobody
 * recorded. Exactly the outstanding amount is fine; a paisa more is not.
 */
class RecordBuyerPayment
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly BuyerOutstandingService $outstanding,
        private readonly SettlementStatusSync $settlements,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  payment_date, amount,
     *                                            financial_account_id, and optionally
     *                                            payment_method_id, reference, notes
     */
    public function handle(Buyer $buyer, array $attributes): BuyerPayment
    {
        $amount = Quantity::money($attributes['amount'] ?? null);
        $date = (string) ($attributes['payment_date'] ?? now()->toDateString());

        $this->assertAmountIsPositive($amount);
        $this->assertBuyerBelongsToBusiness($buyer);

        $account = $this->resolveAccount($attributes['financial_account_id'] ?? null);
        $method = $this->resolveMethod($attributes['payment_method_id'] ?? null);
        $settlement = $this->resolveSettlement($buyer, $attributes['buyer_settlement_id'] ?? null);

        return DB::transaction(function () use ($buyer, $attributes, $amount, $date, $account, $method, $settlement): BuyerPayment {
            /*
             * Lock the buyer's existing payments before measuring what is owed.
             * Outside a lock, two people each recording the final payment of a month
             * would both see the full outstanding and both be allowed.
             */
            BuyerPayment::query()
                ->active()
                ->where('buyer_id', $buyer->getKey())
                ->lockForUpdate()
                ->get();

            $this->assertDoesNotOverpay($buyer, $amount);

            /*
             * A settlement caps the receipt twice over: at the buyer's outstanding
             * above, and at what this settlement still has outstanding. Without the
             * second cap, a Mandali with two finalized months could have one of them
             * recorded as overpaid while the overall balance still looked fine, and
             * its derived status would read Paid on a figure that paid for something
             * else.
             */
            if ($settlement !== null) {
                $this->assertWithinSettlement($settlement, $amount);
            }

            $payment = BuyerPayment::create([
                'buyer_id' => $buyer->getKey(),
                'buyer_settlement_id' => $settlement?->getKey(),
                'payment_date' => $date,
                'amount' => $amount,
                'payment_method_id' => $method?->getKey(),
                'financial_account_id' => $account->getKey(),
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            /*
             * The credit that makes this one entry reach the cashbook, the account
             * balance and the buyer's outstanding at once. The idempotency key is
             * derived from the payment id, so a retried request cannot credit the
             * account twice (docs/DECISIONS.md D20).
             */
            $this->ledger->credit(
                account: $account,
                amount: $amount,
                date: $date,
                description: __('customers.ledger.payment_received_from', ['name' => $buyer->name]),
                idempotencyKey: FinancialLedgerService::key('buyer_payment', $payment->getKey()),
                reference: $payment,
            );

            $this->audit->created($payment, [
                'payment_date' => $date,
                'amount' => $amount,
                'buyer_id' => $buyer->getKey(),
                'buyer_settlement_id' => $settlement?->getKey(),
                'financial_account_id' => $account->getKey(),
                'payment_method_id' => $method?->getKey(),
                'reference' => $attributes['reference'] ?? null,
            ], $this->subject($buyer, $payment));

            // Partially Paid and Paid are derived from the receipts, so the stored
            // status is refreshed from them rather than chosen here.
            $this->settlements->syncStatus($settlement);

            return $payment->refresh();
        });
    }

    /** @throws ValidationException */
    private function assertAmountIsPositive(string $amount): void
    {
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('customers.errors.payment_amount_positive'),
            ]);
        }
    }

    /**
     * Refuses a payment larger than the outstanding balance.
     *
     * The message names both figures, because "too much" without saying how much is
     * not actionable — and a customer paying a round number against an odd balance is
     * a routine situation the user has to resolve deliberately.
     *
     * @throws ValidationException
     */
    private function assertDoesNotOverpay(Buyer $buyer, string $amount): void
    {
        $outstanding = $this->outstanding->outstandingFor($buyer);

        if (bccomp($outstanding, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('customers.errors.nothing_outstanding', ['name' => $buyer->name]),
            ]);
        }

        if (bccomp($amount, $outstanding, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('customers.errors.payment_exceeds_outstanding', [
                    'amount' => $this->money($amount),
                    'outstanding' => $this->money($outstanding),
                ]),
            ]);
        }
    }

    /**
     * The settlement being paid off, checked against the buyer and its state.
     *
     * A draft is refused: it has agreed no amount and has no accounting effect, so a
     * receipt against one would be money recorded against nothing. A cancelled
     * settlement is refused for the obvious reason.
     *
     * @throws ValidationException
     */
    private function resolveSettlement(Buyer $buyer, mixed $settlementId): ?BuyerSettlement
    {
        if (blank($settlementId)) {
            return null;
        }

        $settlement = BuyerSettlement::query()->whereKey($settlementId)->first();

        if (! $settlement || (int) $settlement->buyer_id !== (int) $buyer->getKey()) {
            throw ValidationException::withMessages([
                'buyer_settlement_id' => __('buyers.errors.settlement_wrong_buyer'),
            ]);
        }

        if (! $settlement->status->acceptsPayment()) {
            throw ValidationException::withMessages([
                'buyer_settlement_id' => __('buyers.errors.settlement_not_payable', [
                    'status' => $settlement->status->label(),
                ]),
            ]);
        }

        return $settlement;
    }

    /** @throws ValidationException */
    private function assertWithinSettlement(BuyerSettlement $settlement, string $amount): void
    {
        $remaining = $settlement->remainingAmount();

        if (bccomp($remaining, '0.00', Quantity::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('buyers.errors.settlement_already_paid'),
            ]);
        }

        if (bccomp($amount, $remaining, Quantity::MONEY_SCALE) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('buyers.errors.payment_exceeds_settlement', [
                    'amount' => $this->money($amount),
                    'remaining' => $this->money($remaining),
                ]),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertBuyerBelongsToBusiness(Buyer $buyer): void
    {
        if ((int) $buyer->business_id !== (int) $this->context->business()->getKey()) {
            throw ValidationException::withMessages([
                'buyer' => __('customers.errors.wrong_business'),
            ]);
        }
    }

    /**
     * The receiving account, checked against the business and its active state.
     *
     * An id arriving in a request proves nothing, and crediting another business's
     * account — or one that has been retired — would put the money somewhere nobody
     * is watching.
     *
     * @throws ValidationException
     */
    private function resolveAccount(mixed $accountId): FinancialAccount
    {
        $account = FinancialAccount::query()->whereKey($accountId)->first();

        if (! $account || (int) $account->business_id !== (int) $this->context->business()->getKey()) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('customers.errors.account_not_found'),
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'financial_account_id' => __('customers.errors.account_inactive', ['name' => $account->name]),
            ]);
        }

        return $account;
    }

    /** @throws ValidationException */
    private function resolveMethod(mixed $methodId): ?PaymentMethod
    {
        if (blank($methodId)) {
            return null;
        }

        $method = PaymentMethod::query()->whereKey($methodId)->first();

        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method_id' => __('customers.errors.payment_method_not_found'),
            ]);
        }

        return $method;
    }

    private function subject(Buyer $buyer, BuyerPayment $payment): string
    {
        return sprintf('%s — %s', $buyer->name, $payment->payment_date->format('d-m-Y'));
    }

    private function money(string $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }
}
