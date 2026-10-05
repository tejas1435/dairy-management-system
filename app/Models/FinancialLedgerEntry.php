<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerDirection;
use Database\Factories\FinancialLedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One posting against a financial account.
 *
 * Append-only. Entries are created by App\Services\FinancialLedgerService and
 * never edited or deleted; a mistaken posting is undone with a reversal entry
 * so the original and the correction both remain visible.
 */
class FinancialLedgerEntry extends Model
{
    /** @use HasFactory<FinancialLedgerEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'financial_account_id',
        'entry_date',
        'direction',
        'amount',
        'reference_type',
        'reference_id',
        'description',
        'idempotency_key',
        'reverses_entry_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'direction' => LedgerDirection::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        /*
         * A ledger whose rows can be edited or removed is bookkeeping theatre.
         * These guards make an attempt fail loudly wherever it comes from.
         */
        static::updating(function (): never {
            throw new RuntimeException(
                'Ledger entries are immutable. Post a reversal instead of editing one.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'Ledger entries cannot be deleted. Post a reversal instead.'
            );
        });
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    /** @return BelongsTo<FinancialLedgerEntry, $this> */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }

    /** The signed effect of this entry on the account balance. */
    public function signedAmount(): string
    {
        return $this->direction === LedgerDirection::Credit
            ? (string) $this->amount
            : bcsub('0', (string) $this->amount, 2);
    }

    /**
     * @param  Builder<FinancialLedgerEntry>  $query
     * @return Builder<FinancialLedgerEntry>
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('financial_account_id', $accountId);
    }
}
