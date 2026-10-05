<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\TransactionStatus;
use App\Models\Buyer;
use App\Models\CustomerPause;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerEligibilityService;
use App\Services\Customers\CustomerPauseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pauses a direct customer for a period.
 *
 * The overlap check runs **inside the transaction, after a row lock** on the
 * customer's existing pauses. Checking before the transaction opens would leave the
 * race where two people each add a pause for an overlapping fortnight, both see no
 * clash, and the customer ends up with two overlapping periods — at which point
 * cancelling one leaves them paused for reasons nobody stated.
 *
 * Adjacent periods are allowed. The 1st to the 5th followed by the 6th to the 10th is
 * two separate decisions, and refusing it would force people to edit history to
 * extend a pause.
 */
class CreateCustomerPause
{
    public function __construct(
        private readonly CustomerPauseService $pauses,
        private readonly CustomerEligibilityService $eligibility,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        Buyer $customer,
        string $startDate,
        ?string $endDate = null,
        ?string $reason = null,
    ): CustomerPause {
        $this->eligibility->assertDirectCustomer($customer);
        $this->pauses->assertOrderedDates($startDate, $endDate);

        return DB::transaction(function () use ($customer, $startDate, $endDate, $reason): CustomerPause {
            $customer->pauses()->active()->lockForUpdate()->get();

            $this->pauses->assertNoOverlap($customer, $startDate, $endDate);

            $pause = CustomerPause::create([
                'buyer_id' => $customer->getKey(),
                'start_date' => $startDate,
                'end_date' => $endDate,
                // Optional by specification: a week away needs no justification.
                'reason' => filled($reason) ? $reason : null,
                'status' => TransactionStatus::Active->value,
                'created_by' => Auth::id(),
            ]);

            $this->audit->created($pause, [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $pause->reason,
            ], $this->subject($customer, $pause));

            return $pause;
        });
    }

    private function subject(Buyer $customer, CustomerPause $pause): string
    {
        return sprintf(
            '%s — %s → %s',
            $customer->name,
            $pause->start_date->format('d-m-Y'),
            $pause->end_date?->format('d-m-Y') ?? __('customers.pauses.open_ended'),
        );
    }
}
