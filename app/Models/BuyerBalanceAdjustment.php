<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BalanceAdjustmentDirection;
use App\Enums\TransactionStatus;
use App\Support\Quantity;
use Database\Factories\BuyerBalanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit correction to what a buyer owes.
 *
 * The third term of the outstanding formula. It moves money owed and nothing else:
 * no litre, no reconciliation figure, no financial account. See the migration for
 * why it is a direction plus a positive amount rather than a signed one.
 */
class BuyerBalanceAdjustment extends Model
{
    /** @use HasFactory<BuyerBalanceAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'buyer_settlement_id',
        'adjustment_date',
        'direction',
        'amount',
        'reason',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'direction' => BalanceAdjustmentDirection::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<BuyerSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BuyerSettlement::class, 'buyer_settlement_id');
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
     * Active adjustments only. A cancelled one stops affecting the balance the
     * moment it is cancelled, which is what lets a wrong correction be withdrawn
     * without a second correction to cancel out the first.
     *
     * @param  Builder<BuyerBalanceAdjustment>  $query
     * @return Builder<BuyerBalanceAdjustment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }

    /**
     * The amount with its direction applied, for summing into a balance.
     *
     * The only place the sign exists. It is computed here and never stored, so no
     * column can hold a negative amount and no form has to ask for one.
     */
    public function signedAmount(): string
    {
        $amount = Quantity::money($this->amount);

        return $this->direction === BalanceAdjustmentDirection::Increase
            ? $amount
            : Quantity::negateMoney($amount);
    }
}
