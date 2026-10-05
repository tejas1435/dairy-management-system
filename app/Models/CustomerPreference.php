<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use App\Support\Quantity;
use Database\Factories\CustomerPreferenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which milk a direct customer takes, and roughly how much.
 *
 * **Read the reminder accessors carefully.** `morningReminder()` and
 * `eveningReminder()` return display information. There is deliberately no method
 * on this model that returns a quantity to *save* — no `quantityFor()`, no
 * `defaultQuantity()`, nothing a daily entry screen could mistake for the amount
 * delivered. The reminders are helper text beside an empty field
 * (docs/DECISIONS.md D39).
 */
class CustomerPreference extends Model
{
    /** @use HasFactory<CustomerPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'milk_type',
        'morning_reminder_qty',
        'evening_reminder_qty',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'milk_type' => MilkType::class,
            'morning_reminder_qty' => 'decimal:3',
            'evening_reminder_qty' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * @param  Builder<CustomerPreference>  $query
     * @return Builder<CustomerPreference>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The morning reminder, for display only.
     *
     * Named `reminder` rather than `quantity` at every level — column, method and
     * translation key — so that a caller reaching for a daily quantity does not
     * find this by accident.
     */
    public function morningReminder(): string
    {
        return Quantity::of($this->morning_reminder_qty);
    }

    /** The evening reminder, for display only. */
    public function eveningReminder(): string
    {
        return Quantity::of($this->evening_reminder_qty);
    }

    /** Whether either reminder is worth showing at all. */
    public function hasReminder(): bool
    {
        return ! Quantity::isZero($this->morning_reminder_qty)
            || ! Quantity::isZero($this->evening_reminder_qty);
    }

    /** A compact "M 1.000 / E 2.000" summary for a list column. */
    public function reminderSummary(): string
    {
        if (! $this->hasReminder()) {
            return '—';
        }

        return sprintf(
            '%s %s / %s %s',
            __('customers.reminders.morning_short'),
            number_format((float) $this->morning_reminder_qty, 3),
            __('customers.reminders.evening_short'),
            number_format((float) $this->evening_reminder_qty, 3),
        );
    }
}
