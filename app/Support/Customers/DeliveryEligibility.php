<?php

declare(strict_types=1);

namespace App\Support\Customers;

use App\Enums\MilkType;
use App\Models\Buyer;
use App\Models\CustomerPause;
use App\Models\CustomerPreference;

/**
 * Whether one customer should appear as a delivery row on one date, and what the
 * screen should show beside their empty quantity fields.
 *
 * A value object rather than a bag of booleans so the reasons are inspectable: a
 * screen that only knows "not eligible" cannot tell the user whether the customer
 * is paused, has not started yet, or takes no milk at all.
 *
 * **It carries reminders and it carries no quantities.** `reminderFor()` returns
 * display information; there is deliberately no method here that returns a quantity
 * to save. The whole point of the reminder rule is that the daily entry field starts
 * empty and a human types into it (docs/DECISIONS.md D39).
 */
final readonly class DeliveryEligibility
{
    /**
     * @param  array<string, CustomerPreference>  $activePreferences  keyed by milk type value
     */
    public function __construct(
        public Buyer $customer,
        public string $date,
        public bool $isDirectCustomer,
        public bool $isActive,
        public bool $hasStarted,
        public bool $isPaused,
        public ?CustomerPause $pause,
        public array $activePreferences,
        public ?string $deliveryNote,
    ) {}

    /**
     * Whether a normal delivery row should be offered for this customer and date.
     *
     * All four conditions, in one place. Pass 2's grid asks this rather than
     * assembling the conditions itself, and the sale action asks it again on save
     * because a screen is not an authority.
     */
    public function isDeliverable(): bool
    {
        return $this->isDirectCustomer
            && $this->isActive
            && $this->hasStarted
            && ! $this->isPaused
            && $this->activePreferences !== [];
    }

    /**
     * Why not, for a screen that wants to say something more useful than "no".
     *
     * Ordered by what the user would act on first: a wrong channel is a bug, an
     * archived customer is a decision, a start date is a wait, a pause is
     * temporary, and no preference is a missing setup step.
     */
    public function ineligibleReason(): ?string
    {
        return match (true) {
            ! $this->isDirectCustomer => 'not_direct_customer',
            ! $this->isActive => 'archived',
            ! $this->hasStarted => 'not_started',
            $this->isPaused => 'paused',
            $this->activePreferences === [] => 'no_preference',
            default => null,
        };
    }

    /** A translated explanation of why the customer is not deliverable. */
    public function ineligibleMessage(): ?string
    {
        $reason = $this->ineligibleReason();

        return $reason === null ? null : __('customers.eligibility.'.$reason);
    }

    /** Whether the customer takes this milk type at all. */
    public function takes(MilkType $milkType): bool
    {
        return isset($this->activePreferences[$milkType->value]);
    }

    /**
     * The reminder to show beside the fields for one milk type.
     *
     * Display metadata. Reading this as a quantity to save would defeat the rule it
     * exists to serve, which is why it returns the preference rather than a number
     * and why nothing here is named like a default.
     */
    public function reminderFor(MilkType $milkType): ?CustomerPreference
    {
        return $this->activePreferences[$milkType->value] ?? null;
    }

    /** The milk types this customer takes, in enum order. */
    public function milkTypes(): array
    {
        return array_values(array_filter(
            MilkType::cases(),
            fn (MilkType $type): bool => $this->takes($type),
        ));
    }
}
