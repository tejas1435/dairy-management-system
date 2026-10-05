<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Support\Quantity;
use Database\Factories\MilkSaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One milk sale: a quantity of one milk type, sold to one buyer, in one shift.
 *
 * The canonical distribution record for every channel. `unit_rate` is a snapshot of
 * whatever the price resolver returned when the sale was saved, and `amount` is
 * quantity x rate computed server-side — neither is ever recalculated from current
 * prices, because that would change what last month cost.
 */
class MilkSale extends Model
{
    /** @use HasFactory<MilkSaleFactory> */
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'sales_channel_id',
        'buyer_id',
        'sale_date',
        'shift',
        'milk_type',
        'quantity',
        'unit_rate',
        'amount',
        'fat_percentage',
        'snf_percentage',
        'resolved_rate',
        'rate_override_reason',
        'slip_path',
        'slip_name',
        'source',
        'notes',
        'status',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'shift' => Shift::class,
            'milk_type' => MilkType::class,
            'source' => SaleSource::class,
            'status' => TransactionStatus::class,
            'quantity' => 'decimal:3',
            'unit_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'fat_percentage' => 'decimal:2',
            'resolved_rate' => 'decimal:2',
            'snf_percentage' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Farm, $this> */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
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
     * Active sales only. A cancelled sale stays in the table and the audit trail
     * but must never reach a reconciliation total, an outstanding balance or a
     * revenue figure (MASTER_SPEC section 60).
     *
     * @param  Builder<MilkSale>  $query
     * @return Builder<MilkSale>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    /**
     * The reconciliation unit: one farm, date, shift and milk type.
     *
     * @param  Builder<MilkSale>  $query
     * @return Builder<MilkSale>
     */
    public function scopeForShift(
        Builder $query,
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType
    ): Builder {
        return $query->where('farm_id', $farmId)
            ->whereDate('sale_date', $date)
            ->where('shift', $shift->value)
            ->where('milk_type', $milkType->value);
    }

    /**
     * The one grid-generated sale for a buyer, date, shift and milk type — the
     * identity the database holds unique.
     *
     * @param  Builder<MilkSale>  $query
     * @return Builder<MilkSale>
     */
    public function scopeDailyGridRow(
        Builder $query,
        int $farmId,
        int $buyerId,
        string $date,
        Shift $shift,
        MilkType $milkType
    ): Builder {
        return $query->where('farm_id', $farmId)
            ->where('buyer_id', $buyerId)
            ->whereDate('sale_date', $date)
            ->where('shift', $shift->value)
            ->where('milk_type', $milkType->value)
            ->where('source', SaleSource::CustomerDailyGrid->value);
    }

    /**
     * @param  Builder<MilkSale>  $query
     * @return Builder<MilkSale>
     */
    public function scopeFromSource(Builder $query, SaleSource $source): Builder
    {
        return $query->where('source', $source->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }

    /**
     * What this sale should cost, recomputed from its own snapshot.
     *
     * Used by a test to prove `amount` was never rounded or mis-multiplied. It
     * recalculates from the stored rate, not from current prices.
     *
     * **Quantity times rate, and nothing else.** Fat and SNF are not arguments to
     * this calculation and must never become them: V1 has no fat/SNF pricing formula
     * (MASTER_SPEC section 22), and the rate is typed by hand precisely because the
     * farm agrees it with the Mandali rather than deriving it from quality figures.
     */
    public function expectedAmount(): string
    {
        return Quantity::multiplyToMoney($this->quantity, $this->unit_rate);
    }

    /**
     * Whether this sale departed from the rate the resolver returned.
     *
     * A stored `resolved_rate` is only written when the applied rate differs from it,
     * so its presence *is* the override flag. A Mandali or generic sale has none,
     * because typing the rate is the normal workflow there rather than a departure
     * from anything.
     */
    public function hasRateOverride(): bool
    {
        return $this->resolved_rate !== null;
    }

    public function hasSlip(): bool
    {
        return $this->slip_path !== null && $this->slip_path !== '';
    }

    /** Whether milk quality was recorded, which only a Mandali collection does. */
    public function hasQualityReadings(): bool
    {
        return $this->fat_percentage !== null || $this->snf_percentage !== null;
    }

    /**
     * @param  Builder<MilkSale>  $query
     * @return Builder<MilkSale>
     */
    public function scopeForBuyer(Builder $query, int $buyerId): Builder
    {
        return $query->where('buyer_id', $buyerId);
    }

    /**
     * @param  Builder<MilkSale>  $query
     * @return Builder<MilkSale>
     */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q): Builder => $q->whereDate('sale_date', '>=', $from))
            ->when($to, fn (Builder $q): Builder => $q->whereDate('sale_date', '<=', $to));
    }
}
