<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use App\Enums\TransactionStatus;
use Database\Factories\MilkUsageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilkUsage extends Model
{
    /** @use HasFactory<MilkUsageFactory> */
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'usage_date',
        'shift',
        'milk_type',
        'usage_type',
        'quantity',
        'notes',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'shift' => Shift::class,
            'milk_type' => MilkType::class,
            'usage_type' => MilkUsageType::class,
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
     * Active usage only. A cancelled record stays in the table and the audit
     * trail but must never reach a reconciliation total (MASTER_SPEC section 60).
     *
     * @param  Builder<MilkUsage>  $query
     * @return Builder<MilkUsage>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    /**
     * @param  Builder<MilkUsage>  $query
     * @return Builder<MilkUsage>
     */
    public function scopeForShift(
        Builder $query,
        int $farmId,
        string $date,
        Shift $shift,
        MilkType $milkType
    ): Builder {
        return $query->where('farm_id', $farmId)
            ->whereDate('usage_date', $date)
            ->where('shift', $shift->value)
            ->where('milk_type', $milkType->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }
}
