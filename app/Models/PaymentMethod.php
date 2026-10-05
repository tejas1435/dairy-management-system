<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /** Seeded system methods, matched by code rather than by label. */
    public const CASH = 'cash';

    public const UPI = 'upi';

    public const BANK_TRANSFER = 'bank_transfer';

    public const CHEQUE = 'cheque';

    public const OTHER = 'other';

    protected $fillable = ['name', 'code', 'is_system', 'is_active', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     * @return Builder<PaymentMethod>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     * @return Builder<PaymentMethod>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Whether this method is referenced by any posted record. */
    public function isInUse(): bool
    {
        return $this->partnerContributions()->exists() || $this->fundingAllocations()->exists();
    }

    public function partnerContributions()
    {
        return $this->hasMany(PartnerContribution::class);
    }

    public function fundingAllocations()
    {
        return $this->hasMany(FundingAllocation::class);
    }
}
