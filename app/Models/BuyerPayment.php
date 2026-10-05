<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use Database\Factories\BuyerPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from a buyer, recorded rather than processed.
 *
 * Saving one credits the destination financial account through the ledger, which is
 * what makes a single entry reduce the buyer's outstanding, appear in the cashbook
 * and move the account balance. Cancelling it posts one reversing debit.
 */
class BuyerPayment extends Model
{
    /** @use HasFactory<BuyerPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'buyer_settlement_id',
        'payment_date',
        'amount',
        'payment_method_id',
        'financial_account_id',
        'reference',
        'notes',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'status' => TransactionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * The settlement this receipt pays off, when it pays one off.
     *
     * Optional: a direct customer pays a running balance and a Mandali may also pay
     * on account. The link is what lets a settlement's payment status be derived from
     * its receipts instead of stored (Phase 5).
     *
     * @return BelongsTo<BuyerSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BuyerSettlement::class, 'buyer_settlement_id');
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Active payments only. A cancelled payment stops reducing outstanding, which
     * is exactly what its reversal in the ledger mirrors.
     *
     * @param  Builder<BuyerPayment>  $query
     * @return Builder<BuyerPayment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }
}
