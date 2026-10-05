<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettlementStatus;
use App\Enums\TransactionStatus;
use App\Support\Quantity;
use Database\Factories\BuyerSettlementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Mandali settlement for a period.
 *
 * Its figures are snapshots taken at finalization, not a live query — see the
 * migration for why. What is *not* stored is how much has been paid: that is the sum
 * of active receipts linked to it, so the two payment statuses are derived and
 * cannot drift from the receipts.
 */
class BuyerSettlement extends Model
{
    /** @use HasFactory<BuyerSettlementFactory> */
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'period_start',
        'period_end',
        'milk_quantity',
        'expected_amount',
        'statement_amount',
        'difference',
        'status',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => SettlementStatus::class,
            'milk_quantity' => 'decimal:3',
            'expected_amount' => 'decimal:2',
            'statement_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return HasMany<BuyerPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BuyerPayment::class, 'buyer_settlement_id');
    }

    /**
     * The one adjustment this settlement's difference created, if it created one.
     *
     * `hasOne` rather than `hasMany` because the database holds it to at most one
     * active row per settlement.
     *
     * @return HasOne<BuyerBalanceAdjustment, $this>
     */
    public function adjustment(): HasOne
    {
        return $this->hasOne(BuyerBalanceAdjustment::class, 'buyer_settlement_id')
            ->where('status', TransactionStatus::Active->value);
    }

    /** @return HasMany<BuyerBalanceAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(BuyerBalanceAdjustment::class, 'buyer_settlement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Settlements that still count: anything not cancelled.
     *
     * @param  Builder<BuyerSettlement>  $query
     * @return Builder<BuyerSettlement>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', SettlementStatus::Cancelled->value);
    }

    /**
     * Settlements whose figures and adjustment are in force.
     *
     * @param  Builder<BuyerSettlement>  $query
     * @return Builder<BuyerSettlement>
     */
    public function scopeFinalized(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SettlementStatus::Finalized->value,
            SettlementStatus::PartiallyPaid->value,
            SettlementStatus::Paid->value,
        ]);
    }

    /**
     * Settlements whose period contains a date.
     *
     * Inclusive at both ends, matching every other period in this schema.
     *
     * @param  Builder<BuyerSettlement>  $query
     * @return Builder<BuyerSettlement>
     */
    public function scopeCoveringDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date);
    }

    /**
     * Settlements whose period overlaps another, for the no-double-settling rule.
     *
     * Two inclusive ranges overlap unless one ends before the other starts.
     *
     * @param  Builder<BuyerSettlement>  $query
     * @return Builder<BuyerSettlement>
     */
    public function scopeOverlapping(Builder $query, string $start, string $end): Builder
    {
        return $query->whereDate('period_start', '<=', $end)
            ->whereDate('period_end', '>=', $start);
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    public function isFinalized(): bool
    {
        return $this->status->isFinalized();
    }

    public function isCancelled(): bool
    {
        return $this->status->isCancelled();
    }

    /**
     * What the Mandali actually has to pay.
     *
     * The statement amount when one was given, otherwise the system's expected
     * figure. The difference between the two has already become an adjustment, so
     * using the statement here is not double-counting: the adjustment moved the
     * buyer's running balance, and this is what settles *this period*.
     */
    public function amountDue(): string
    {
        return Quantity::money($this->statement_amount ?? $this->expected_amount);
    }

    /**
     * The total received against this settlement, from active receipts only.
     *
     * Reads an already-loaded `payments` relation when there is one, because the
     * figures derived from this — the amount remaining and the payment status — are
     * shown once per settlement on a list, and a query per row there grows with the
     * history for ever. The caller eager-loads; the arithmetic stays in one place
     * either way, and `sumMoney()` is used rather than `Collection::sum()` so the
     * in-memory path adds decimal strings rather than floats.
     */
    public function paidAmount(): string
    {
        if ($this->relationLoaded('payments')) {
            return Quantity::sumMoney(
                $this->payments
                    ->where('status', TransactionStatus::Active)
                    ->pluck('amount')
            );
        }

        return Quantity::money($this->payments()->where('status', TransactionStatus::Active->value)->sum('amount'));
    }

    /** What is still owed on this settlement, never below zero. */
    public function remainingAmount(): string
    {
        $remaining = bcsub($this->amountDue(), $this->paidAmount(), Quantity::MONEY_SCALE);

        return bccomp($remaining, '0.00', Quantity::MONEY_SCALE) < 0 ? '0.00' : $remaining;
    }

    public function hasStatement(): bool
    {
        return $this->statement_amount !== null;
    }

    /** Whether finalization found a difference worth recording. */
    public function hasDifference(): bool
    {
        return $this->difference !== null
            && bccomp(Quantity::money($this->difference), '0.00', Quantity::MONEY_SCALE) !== 0;
    }

    /**
     * The payment status this settlement's receipts imply.
     *
     * Derived here and written by the payment actions, so the stored status is always
     * a reflection of the receipts rather than a second opinion about them. A
     * cancelled settlement keeps its status: withdrawing it is not a payment event.
     */
    public function derivedStatus(): SettlementStatus
    {
        if (! $this->isFinalized()) {
            return $this->status;
        }

        $paid = $this->paidAmount();
        $due = $this->amountDue();

        return match (true) {
            bccomp($paid, '0.00', Quantity::MONEY_SCALE) <= 0 => SettlementStatus::Finalized,
            bccomp($paid, $due, Quantity::MONEY_SCALE) >= 0 => SettlementStatus::Paid,
            default => SettlementStatus::PartiallyPaid,
        };
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('d-m-Y').' — '.$this->period_end->format('d-m-Y');
    }
}
