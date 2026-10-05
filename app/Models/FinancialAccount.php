<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Enums\LedgerDirection;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A business cash or bank account.
 *
 * **There is no current_balance column.** The balance is
 * `opening_balance + credits - debits`, computed from the ledger every time it
 * is asked for. A stored balance can silently disagree with the entries that
 * produced it, and when it does there is no way to tell which is right.
 */
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'opening_balance',
        'is_active',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<FinancialLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class);
    }

    /** @return HasMany<PartnerContribution, $this> */
    public function partnerContributions(): HasMany
    {
        return $this->hasMany(PartnerContribution::class);
    }

    /**
     * Expenses and other payables funded from this account.
     *
     * @return MorphMany<FundingAllocation, $this>
     */
    public function fundingAllocations(): MorphMany
    {
        return $this->morphMany(FundingAllocation::class, 'source');
    }

    /**
     * The current balance, derived.
     *
     * Returned as a string so the decimal survives without going through a
     * float. Callers comparing money should use bccomp or compare strings, not
     * `==` on floats.
     */
    public function balance(?string $upTo = null): string
    {
        $query = $this->ledgerEntries();

        if ($upTo !== null) {
            $query->whereDate('entry_date', '<=', $upTo);
        }

        $credits = (clone $query)->where('direction', LedgerDirection::Credit->value)->sum('amount');
        $debits = (clone $query)->where('direction', LedgerDirection::Debit->value)->sum('amount');

        return bcadd(
            bcsub((string) $this->opening_balance, '0', 2),
            bcsub((string) $credits, (string) $debits, 2),
            2
        );
    }

    /**
     * Eager-loads the derived balance for a list of accounts in one query
     * instead of one per row.
     *
     * @param  Builder<FinancialAccount>  $query
     * @return Builder<FinancialAccount>
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query
            ->withSum(['ledgerEntries as ledger_credits' => fn ($q) => $q->where('direction', LedgerDirection::Credit->value)], 'amount')
            ->withSum(['ledgerEntries as ledger_debits' => fn ($q) => $q->where('direction', LedgerDirection::Debit->value)], 'amount');
    }

    /**
     * The balance from the aggregates loaded by scopeWithBalance().
     *
     * Falls back to a direct query if the scope was not used, so a caller
     * cannot silently read a wrong number.
     */
    public function loadedBalance(): string
    {
        if (! array_key_exists('ledger_credits', $this->attributes)) {
            return $this->balance();
        }

        return bcadd(
            bcsub((string) $this->opening_balance, '0', 2),
            bcsub((string) ($this->ledger_credits ?? 0), (string) ($this->ledger_debits ?? 0), 2),
            2
        );
    }

    /**
     * @param  Builder<FinancialAccount>  $query
     * @return Builder<FinancialAccount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasFinancialHistory(): bool
    {
        return $this->ledgerEntries()->exists();
    }

    public function label(): string
    {
        return $this->name;
    }
}
