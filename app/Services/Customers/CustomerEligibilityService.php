<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Buyer;
use App\Models\CustomerPreference;
use App\Models\SalesChannel;
use App\Services\BusinessContext;
use App\Support\Customers\DeliveryEligibility;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Decides which direct customers should appear as delivery rows on a given date.
 *
 * Four conditions have to agree: the buyer is a direct customer, is active, has
 * started by that date, and takes at least one milk type. A fifth — the pause —
 * changes the answer temporarily.
 *
 * They live here rather than in the Blade template or the grid controller because
 * Pass 2's Customer Daily Entry and the sale action both have to apply them, and
 * they have to apply them identically. A grid that shows a row the server would
 * refuse, or hides one the server would accept, is worse than either behaviour
 * consistently.
 *
 * The batch method exists for the same reason: Pass 2 loads every eligible customer
 * for one date, so the per-customer form of these questions would be several
 * hundred queries.
 */
class CustomerEligibilityService
{
    public function __construct(
        private readonly CustomerPauseService $pauses,
        private readonly BusinessContext $context,
    ) {}

    /** Eligibility for one customer on one date. */
    public function for(Buyer $customer, string $date): DeliveryEligibility
    {
        $customer->loadMissing(['salesChannel:id,slug', 'preferences']);

        $pause = $this->pauses->pauseOn($customer, $date);

        return new DeliveryEligibility(
            customer: $customer,
            date: $date,
            isDirectCustomer: $customer->isDirectCustomer(),
            isActive: (bool) $customer->is_active,
            hasStarted: $customer->hasStartedBy($date),
            isPaused: $pause !== null,
            pause: $pause,
            activePreferences: $this->activePreferences($customer),
            deliveryNote: $customer->delivery_note,
        );
    }

    /**
     * Eligibility for every direct customer who could plausibly appear on a date,
     * keyed by buyer id.
     *
     * "Could plausibly appear" is deliberately narrower than "is deliverable": the
     * query filters on the conditions a database can express — channel, active
     * state, start date, at least one active preference — and the pause is resolved
     * in one further query. A customer who is merely paused is still returned, so
     * the grid can show them marked rather than silently drop them, which is what
     * MASTER_SPEC section 18 asks for.
     *
     * @return Collection<int, DeliveryEligibility>
     */
    public function forDate(string $date, ?int $businessId = null): Collection
    {
        $businessId ??= $this->context->business()->getKey();

        $customers = Buyer::query()
            ->with(['salesChannel:id,slug', 'preferences'])
            ->where('business_id', $businessId)
            ->directCustomers()
            ->active()
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date))
            ->whereHas('preferences', fn ($q) => $q->where('is_active', true))
            ->orderBy('area')
            ->orderBy('name')
            ->get();

        $pausedMap = $this->pauses->pausedMapFor($customers->pluck('id')->all(), $date);

        return $customers->mapWithKeys(fn (Buyer $customer): array => [
            $customer->getKey() => new DeliveryEligibility(
                customer: $customer,
                date: $date,
                isDirectCustomer: true,
                isActive: true,
                hasStarted: true,
                isPaused: $pausedMap[$customer->getKey()] ?? false,
                // Not loaded per customer: the grid needs the flag, and the reason
                // only when somebody opens the profile.
                pause: null,
                activePreferences: $this->activePreferences($customer),
                deliveryNote: $customer->delivery_note,
            ),
        ]);
    }

    /**
     * Refuses a buyer that is not a direct customer.
     *
     * Every direct-customer workflow calls this before touching the record. An id
     * arriving in a URL proves only that a row exists: `buyers` also holds Mandalis
     * and vendors, and a Mandali does not have milk preferences, a delivery round or
     * a pause schedule. Letting one into this workflow would not fail loudly — it
     * would quietly create customer data against the wrong kind of buyer.
     *
     * @throws ValidationException
     */
    public function assertDirectCustomer(Buyer $buyer): void
    {
        if ($buyer->isDirectCustomer()) {
            return;
        }

        throw ValidationException::withMessages([
            'buyer' => __('customers.errors.not_a_direct_customer'),
        ]);
    }

    /** The seeded direct-customer channel for the operating business. */
    public function channel(?int $businessId = null): SalesChannel
    {
        $businessId ??= $this->context->business()->getKey();

        return SalesChannel::query()
            ->where('business_id', $businessId)
            ->where('slug', SalesChannel::DIRECT_CUSTOMER)
            ->firstOrFail();
    }

    /**
     * Active preferences keyed by milk type value.
     *
     * @return array<string, CustomerPreference>
     */
    private function activePreferences(Buyer $customer): array
    {
        $active = [];

        foreach ($customer->preferences as $preference) {
            if ($preference->is_active) {
                $active[$preference->milk_type->value] = $preference;
            }
        }

        return $active;
    }
}
