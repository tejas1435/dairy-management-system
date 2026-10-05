<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\MilkType;
use App\Models\Buyer;
use App\Models\CustomerPreference;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerEligibilityService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Saves which milk types a direct customer takes, and their reminders.
 *
 * One row per milk type, created when first needed and updated afterwards, so the
 * `(buyer_id, milk_type)` unique key is never contended.
 *
 * A milk type the customer stops taking is **deactivated rather than deleted**. The
 * historical sales of that milk are still on the ledger and still have to be
 * explainable; a deleted preference would leave a month of buffalo deliveries with
 * nothing saying the customer ever took buffalo. Reactivating later reuses the row,
 * with the reminders as they were last set.
 *
 * The reminders saved here are display metadata and nothing else
 * (docs/DECISIONS.md D39).
 */
class SaveCustomerPreferences
{
    public function __construct(
        private readonly CustomerEligibilityService $eligibility,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, array{is_active?: bool|string|null, morning?: string|null, evening?: string|null}>  $preferences
     *                                                                                                                         keyed by MilkType value; a milk type left out is not touched
     * @return array<string, CustomerPreference>
     */
    public function handle(Buyer $customer, array $preferences): array
    {
        $this->eligibility->assertDirectCustomer($customer);

        return DB::transaction(function () use ($customer, $preferences): array {
            $saved = [];

            foreach (MilkType::cases() as $milkType) {
                if (! array_key_exists($milkType->value, $preferences)) {
                    continue;
                }

                $saved[$milkType->value] = $this->persist(
                    $customer,
                    $milkType,
                    $preferences[$milkType->value],
                );
            }

            return $saved;
        });
    }

    /**
     * @param  array{is_active?: bool|string|null, morning?: string|null, evening?: string|null}  $row
     */
    private function persist(Buyer $customer, MilkType $milkType, array $row): CustomerPreference
    {
        $active = filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $morning = Quantity::of($row['morning'] ?? null);
        $evening = Quantity::of($row['evening'] ?? null);

        $existing = CustomerPreference::query()
            ->where('buyer_id', $customer->getKey())
            ->where('milk_type', $milkType->value)
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            /*
             * An inactive preference that has never existed is nothing at all, so a
             * row is not created for it. Otherwise every customer would carry a row
             * per milk type they have never taken.
             */
            if (! $active) {
                return new CustomerPreference([
                    'buyer_id' => $customer->getKey(),
                    'milk_type' => $milkType->value,
                    'morning_reminder_qty' => Quantity::ZERO,
                    'evening_reminder_qty' => Quantity::ZERO,
                    'is_active' => false,
                ]);
            }

            $preference = CustomerPreference::create([
                'buyer_id' => $customer->getKey(),
                'milk_type' => $milkType->value,
                'morning_reminder_qty' => $morning,
                'evening_reminder_qty' => $evening,
                'is_active' => true,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->audit->created($preference, [
                'milk_type' => $milkType->value,
                'morning_reminder_qty' => $morning,
                'evening_reminder_qty' => $evening,
                'is_active' => true,
            ], $this->subject($customer, $milkType));

            return $preference;
        }

        $before = [
            'morning_reminder_qty' => Quantity::of($existing->morning_reminder_qty),
            'evening_reminder_qty' => Quantity::of($existing->evening_reminder_qty),
            'is_active' => $existing->is_active,
        ];

        $existing->forceFill([
            'morning_reminder_qty' => $morning,
            'evening_reminder_qty' => $evening,
            'is_active' => $active,
            'updated_by' => Auth::id(),
        ])->save();

        // Diff-only, so re-saving the profile without changing a reminder writes no
        // audit noise.
        $this->audit->updated($existing, $before, [
            'morning_reminder_qty' => $morning,
            'evening_reminder_qty' => $evening,
            'is_active' => $active,
        ], $this->subject($customer, $milkType));

        return $existing->refresh();
    }

    private function subject(Buyer $customer, MilkType $milkType): string
    {
        return $customer->name.' — '.$milkType->label();
    }
}
