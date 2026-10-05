<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'farm_id',
        'expense_category_id',
        'expense_date',
        'amount',
        'description',
        'payee_name',
        'notes',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'status' => TransactionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Farm, $this> */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return MorphMany<FundingAllocation, $this> */
    public function fundingAllocations(): MorphMany
    {
        return $this->morphMany(FundingAllocation::class, 'payable');
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
     * Active expenses only. Cancelled ones stay in the table and in history but
     * must never reach a total.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }

    /** Sum of what the funding sources have been recorded as paying. */
    public function allocatedAmount(): string
    {
        return bcadd((string) $this->fundingAllocations()->sum('amount'), '0', 2);
    }

    public function isFullyFunded(): bool
    {
        return bccomp($this->allocatedAmount(), (string) $this->amount, 2) === 0;
    }
}
