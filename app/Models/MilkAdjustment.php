<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use App\Support\Quantity;
use Database\Factories\MilkAdjustmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilkAdjustment extends Model
{
    /** @use HasFactory<MilkAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'adjustment_date',
        'shift',
        'milk_type',
        'direction',
        'quantity',
        'reason',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'shift' => Shift::class,
            'milk_type' => MilkType::class,
            'direction' => AdjustmentDirection::class,
            'quantity' => 'decimal:3',
            'status' => TransactionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Farm, $this> */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
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
     * The effect on available milk: positive for an increase, negative for a
     * decrease.
     *
     * The sign exists only here, in the arithmetic. The stored quantity is always
     * positive and the direction is explicit (docs/DECISIONS.md D31).
     */
    public function signedQuantity(): string
    {
        return Quantity::signed($this->quantity, $this->direction->sign());
    }

    /**
     * @param  Builder<MilkAdjustment>  $query
     * @return Builder<MilkAdjustment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    /**
     * @param  Builder<MilkAdjustment>  $query
     * @return Builder<MilkAdjustment>
     */
    public function scopeForShift(
        Builder $query,
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType
    ): Builder {
        return $query->where('farm_id', $farmId)
            ->whereDate('adjustment_date', $date)
            ->where('shift', $shift->value)
            ->where('milk_type', $milkType->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }
}
