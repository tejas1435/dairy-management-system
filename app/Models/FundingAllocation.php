<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FundingAllocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One source paying one share of one payable.
 *
 * An allocation is **not** an expense. A 10,000 expense split across two
 * sources is one expense and two allocations; any report that added the
 * allocations to the expense would double-count the money.
 */
class FundingAllocation extends Model
{
    /** @use HasFactory<FundingAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'payable_type',
        'payable_id',
        'source_type',
        'source_id',
        'amount',
        'payment_method_id',
        'reference',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo('payable');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isPartnerFunded(): bool
    {
        return $this->source_type === 'partner';
    }

    public function isAccountFunded(): bool
    {
        return $this->source_type === 'financial_account';
    }

    /**
     * @param  Builder<FundingAllocation>  $query
     * @return Builder<FundingAllocation>
     */
    public function scopeFromPartner(Builder $query, int $partnerId): Builder
    {
        return $query->where('source_type', 'partner')->where('source_id', $partnerId);
    }
}
