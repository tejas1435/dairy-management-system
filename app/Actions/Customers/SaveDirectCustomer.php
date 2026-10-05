<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Buyer;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\Customers\CustomerEligibilityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a direct customer, with its milk preferences, in one operation.
 *
 * **The sales channel is assigned server-side and never taken from the request.** A
 * direct customer is a buyer in the `direct_customer` channel, and that channel is
 * resolved from the seeded slug. A posted `sales_channel_id` is ignored on create and
 * cannot move an existing customer on update — the generic buyer master in
 * `BuyerController` is where a deliberate channel change belongs, and it re-checks
 * the destination channel's permission before allowing one.
 *
 * Without that rule, this screen would be a way to turn a customer into a Mandali:
 * the record would keep its preferences and pauses while its permission family
 * silently changed to `mandali.*`, so whoever could edit it before would lose access
 * and a Mandali would acquire a delivery round.
 *
 * Preferences are saved in the same transaction, because a customer created with no
 * milk type is not yet usable and a half-saved profile is worse than a refused one.
 */
class SaveDirectCustomer
{
    public function __construct(
        private readonly CustomerEligibilityService $eligibility,
        private readonly SaveCustomerPreferences $preferences,
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    /** The buyer attributes this screen owns. */
    private const TRACKED = [
        'name', 'mobile', 'email', 'address', 'area',
        'delivery_note', 'payment_cycle', 'start_date',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>|null  $preferences  keyed by MilkType value
     */
    public function create(array $attributes, ?array $preferences = null): Buyer
    {
        $channel = $this->eligibility->channel();

        return DB::transaction(function () use ($attributes, $preferences, $channel): Buyer {
            $customer = Buyer::create($this->buyerAttributes($attributes) + [
                'business_id' => $this->context->business()->getKey(),
                // Assigned, not accepted.
                'sales_channel_id' => $channel->getKey(),
                'is_active' => true,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($customer, $this->buyerAttributes($attributes) + [
                'sales_channel_id' => $channel->getKey(),
            ], $customer->name);

            if ($preferences !== null) {
                $this->preferences->handle($customer, $preferences);
            }

            return $customer->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>|null  $preferences
     */
    public function update(Buyer $customer, array $attributes, ?array $preferences = null): Buyer
    {
        $this->eligibility->assertDirectCustomer($customer);

        return DB::transaction(function () use ($customer, $attributes, $preferences): Buyer {
            $before = $customer->only(self::TRACKED);

            // sales_channel_id is absent from the fill, so no request can move this
            // customer out of the direct-customer channel through this screen.
            $customer->fill($this->buyerAttributes($attributes))->save();

            $this->audit->updated($customer, $before, $customer->only(self::TRACKED), $customer->name);

            if ($preferences !== null) {
                $this->preferences->handle($customer, $preferences);
            }

            return $customer->refresh();
        });
    }

    /**
     * Archives or restores a customer.
     *
     * Archiving stops them appearing as a delivery row and stops new sales being
     * recorded against them. It changes nothing that already happened: past sales,
     * payments, ledger and price history stay exactly as they are and stay readable
     * (MASTER_SPEC section 60).
     */
    public function setActiveState(Buyer $customer, bool $active): Buyer
    {
        $this->eligibility->assertDirectCustomer($customer);

        return DB::transaction(function () use ($customer, $active): Buyer {
            $customer->forceFill(['is_active' => $active])->save();

            $this->audit->statusChanged($customer, $active, $customer->name);

            return $customer->refresh();
        });
    }

    /**
     * Only the attributes this screen owns, with blanks normalised to null.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function buyerAttributes(array $attributes): array
    {
        $clean = [];

        foreach (self::TRACKED as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];
            $clean[$field] = filled($value) ? $value : null;
        }

        // Name is required and must not be nulled by an empty submission.
        if (array_key_exists('name', $clean) && $clean['name'] === null) {
            unset($clean['name']);
        }

        return $clean;
    }
}
