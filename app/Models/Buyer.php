<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilkType;
use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Anyone the business sells milk to.
 *
 * One identity for a Mandali, a vendor, a direct customer or a future channel.
 * Outstanding balance is derived from sales, payments and adjustments in later
 * phases, and is never stored here.
 */
class Buyer extends Model
{
    /** @use HasFactory<BuyerFactory> */
    use HasFactory;

    protected $fillable = [
        'business_id',
        'sales_channel_id',
        'name',
        'mobile',
        'email',
        'address',
        'area',
        'delivery_note',
        'payment_cycle',
        'start_date',
        'is_active',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'start_date' => 'date',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    /** @return HasMany<BuyerPriceRule, $this> */
    public function priceRules(): HasMany
    {
        return $this->hasMany(BuyerPriceRule::class);
    }

    /**
     * Milk preferences. Only meaningful for a direct customer, which is why
     * everything that writes them goes through the direct-customer guard first.
     *
     * @return HasMany<CustomerPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(CustomerPreference::class);
    }

    /** @return HasMany<CustomerPause, $this> */
    public function pauses(): HasMany
    {
        return $this->hasMany(CustomerPause::class);
    }

    /** @return HasMany<MilkSale, $this> */
    public function milkSales(): HasMany
    {
        return $this->hasMany(MilkSale::class);
    }

    /** @return HasMany<BuyerPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BuyerPayment::class);
    }

    /**
     * Explicit corrections to what this buyer owes (Phase 5).
     *
     * @return HasMany<BuyerBalanceAdjustment, $this>
     */
    public function balanceAdjustments(): HasMany
    {
        return $this->hasMany(BuyerBalanceAdjustment::class);
    }

    /**
     * Period settlements, which in V1 only a Mandali has.
     *
     * The relation lives on `Buyer` rather than on a Mandali model because there is
     * no Mandali model — a Mandali is a buyer in the Mandali channel (D26).
     *
     * @return HasMany<BuyerSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(BuyerSettlement::class);
    }

    /**
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    public function scopeInChannel(Builder $query, string $slug): Builder
    {
        return $query->whereHas('salesChannel', fn ($q) => $q->where('slug', $slug));
    }

    public function channelSlug(): ?string
    {
        return $this->salesChannel?->slug;
    }

    /**
     * Direct customers only.
     *
     * Every direct-customer query starts here rather than filtering on a channel id
     * a request supplied. A Mandali or a vendor is also a row in this table, and
     * accepting any id that exists is how one ends up in a customer workflow that
     * assumes milk preferences and a delivery round.
     *
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    public function scopeDirectCustomers(Builder $query): Builder
    {
        return $query->inChannel(SalesChannel::DIRECT_CUSTOMER);
    }

    /** Whether this buyer is a direct customer, by its channel's stable slug. */
    public function isDirectCustomer(): bool
    {
        return $this->channelSlug() === SalesChannel::DIRECT_CUSTOMER;
    }

    /**
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    public function scopeMandalis(Builder $query): Builder
    {
        return $query->inChannel(SalesChannel::MANDALI);
    }

    public function isMandali(): bool
    {
        return $this->channelSlug() === SalesChannel::MANDALI;
    }

    /**
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    public function scopeVendors(Builder $query): Builder
    {
        return $query->inChannel(SalesChannel::VENDOR);
    }

    public function isVendor(): bool
    {
        return $this->channelSlug() === SalesChannel::VENDOR;
    }

    /**
     * Buyers in an administrator-created channel — a hotel, a sweet shop, a bulk
     * buyer.
     *
     * Defined as "not one of the three system channels" rather than by listing
     * custom slugs, because the whole point of a custom channel is that nobody knows
     * its name in advance.
     *
     * @param  Builder<Buyer>  $query
     * @return Builder<Buyer>
     */
    public function scopeCustomChannel(Builder $query): Builder
    {
        return $query->whereHas(
            'salesChannel',
            fn (Builder $channel): Builder => $channel->whereNotIn('slug', SalesChannel::systemSlugs())
        );
    }

    /**
     * Whether this buyer belongs to a channel with no specialised workflow.
     *
     * The three system channels each have their own entry screen with their own
     * rules; everything else uses the generic sale form, and the generic form
     * refuses the three (MASTER_SPEC section 25).
     */
    public function isCustomChannel(): bool
    {
        $slug = $this->channelSlug();

        return $slug !== null && ! in_array($slug, SalesChannel::systemSlugs(), true);
    }

    /**
     * Whether the customer had started by the given business date.
     *
     * A null start date means "from the beginning", which is how a customer created
     * before the field existed behaves. Compared on the `DATE` column, so no
     * timezone conversion is involved.
     */
    public function hasStartedBy(string $date): bool
    {
        return $this->start_date === null
            || $this->start_date->lte(Carbon::parse($date)->startOfDay());
    }

    /** The active preference for one milk type, or null if the customer takes none. */
    public function activePreferenceFor(MilkType $milkType): ?CustomerPreference
    {
        return $this->preferences
            ->first(fn (CustomerPreference $preference): bool => $preference->is_active
                && $preference->milk_type === $milkType);
    }
}
