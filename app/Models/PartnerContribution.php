<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use Database\Factories\PartnerContributionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerContribution extends Model
{
    /** @use HasFactory<PartnerContributionFactory> */
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'financial_account_id',
        'payment_method_id',
        'contribution_date',
        'amount',
        'reference',
        'notes',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contribution_date' => 'date',
            'amount' => 'decimal:2',
            'status' => TransactionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
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

    /**
     * @param  Builder<PartnerContribution>  $query
     * @return Builder<PartnerContribution>
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
