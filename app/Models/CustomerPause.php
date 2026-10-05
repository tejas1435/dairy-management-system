<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Services\Customers\CustomerPauseService;
use Database\Factories\CustomerPauseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period during which a direct customer takes no milk.
 *
 * The date arithmetic lives in scopes here and in
 * {@see CustomerPauseService}, never in a controller or a
 * Blade template. "Is this customer paused on this date" is asked once per customer
 * per daily-entry page load, and an overlap rule spread across three call sites is
 * an overlap rule that will disagree with itself.
 */
class CustomerPause extends Model
{
    /** @use HasFactory<CustomerPauseFactory> */
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'start_date',
        'end_date',
        'reason',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => TransactionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
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
     * @param  Builder<CustomerPause>  $query
     * @return Builder<CustomerPause>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Active->value);
    }

    /**
     * Pauses that cover the given business date.
     *
     * An open-ended pause (`end_date` null) covers every date from its start
     * onwards. Compared with `whereDate` on `DATE` columns, so no timezone
     * conversion is involved (docs/DECISIONS.md D7).
     *
     * @param  Builder<CustomerPause>  $query
     * @return Builder<CustomerPause>
     */
    public function scopeCoveringDate(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
    }

    /**
     * Pauses whose period intersects the given range at all.
     *
     * Two periods overlap unless one ends before the other starts. Both ends are
     * nullable-open, so the comparison is written as "not disjoint" rather than as
     * a list of cases.
     *
     * @param  Builder<CustomerPause>  $query
     * @return Builder<CustomerPause>
     */
    public function scopeOverlapping(Builder $query, string $start, ?string $end): Builder
    {
        return $query
            // The existing pause must not end before the new one starts.
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            // And must not start after the new one ends, unless the new one is open.
            ->when($end !== null, fn ($q) => $q->whereDate('start_date', '<=', $end));
    }

    public function isCancelled(): bool
    {
        return $this->status === TransactionStatus::Cancelled;
    }

    public function isOpenEnded(): bool
    {
        return $this->end_date === null;
    }

    /** Whether this pause covers the given date. */
    public function covers(string $date): bool
    {
        $target = Carbon::parse($date)->startOfDay();

        if ($this->start_date->gt($target)) {
            return false;
        }

        return $this->end_date === null || $this->end_date->gte($target);
    }

    /** Where this pause sits relative to a reference date, for display. */
    public function state(?string $on = null): string
    {
        $on ??= now()->toDateString();

        return match (true) {
            $this->isCancelled() => 'cancelled',
            $this->covers($on) => 'current',
            $this->start_date->gt(Carbon::parse($on)) => 'upcoming',
            default => 'past',
        };
    }

    public function stateBadge(?string $on = null): string
    {
        return match ($this->state($on)) {
            'current' => 'text-warning-emphasis bg-warning-subtle border border-warning-subtle',
            'upcoming' => 'text-primary-emphasis bg-primary-subtle border border-primary-subtle',
            'cancelled' => 'text-danger-emphasis bg-danger-subtle border border-danger-subtle',
            default => 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle',
        };
    }
}
