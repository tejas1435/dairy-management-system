<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Buyers\RecordBuyerPayment;
use App\Actions\Milk\SaveCustomerDailySale;
use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Models\Buyer;
use App\Models\BuyerPayment;
use App\Models\FinancialAccount;
use App\Models\MilkSale;
use App\Models\PaymentMethod;
use App\Services\BusinessContext;
use App\Services\Buyers\BuyerOutstandingService;
use App\Support\Quantity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Development direct-customer deliveries and receipts.
 *
 * Phase 4 Pass 1 deliberately seeded none of this, because the workflow that creates
 * a sale did not exist and a row written straight into `milk_sales` would have been a
 * financial history nobody entered. Pass 2 built the workflow, so the boundary closes
 * here — and these rows go in through the **real domain action**, not through
 * `insert()`. Availability, pricing, the rate snapshot, the grid identity and the
 * audit record all apply exactly as they would to an operator typing the day in.
 *
 * ## It cannot over-allocate
 *
 * The dates are the two days the Phase 3 seeder recorded in full, and the quantities
 * are a fraction of what was produced. If that ever stops being true,
 * `MilkAvailability` refuses the sale and the seed fails loudly rather than quietly
 * producing a farm that distributed milk it never had. Yesterday is avoided because
 * its evening shift is deliberately unentered, and the two customers whose pauses
 * cover these dates get no deliveries.
 *
 * ## Quantities are not reminders
 *
 * Every figure below differs from the customer's reminder. A demo dataset where the
 * two happen to match would make the single most important rule in the phase
 * invisible — and would quietly train the next reader to expect them to be the same.
 *
 * ## Idempotency
 *
 * A sale is skipped when the recorded quantity already matches, so re-seeding writes
 * no row and no audit record. Payments are identified by a stable reference. Nothing
 * here uses "if the table is empty", which would stop working the moment a later
 * seeder legitimately added a row.
 *
 * Tests do not read any of this; they build their own data with factories. What the
 * tests do assert is that the seed is internally consistent.
 */
class CustomerSalesSeeder extends Seeder
{
    /** Marks the demo receipts, so re-seeding recognises its own work. */
    public const PAYMENT_REFERENCE_PREFIX = 'SEED-DEMO-';

    public function run(): void
    {
        $business = app(BusinessContext::class)->business();
        $farmId = app(BusinessContext::class)->primaryFarm()->getKey();

        $customers = Buyer::query()
            ->with('preferences')
            ->where('business_id', $business->getKey())
            ->directCustomers()
            ->get()
            ->keyBy('name');

        if ($customers->isEmpty()) {
            $this->command?->warn('Customer sales skipped: no direct customers are seeded.');

            return;
        }

        $sales = $this->seedDeliveries($customers, $farmId);
        $payments = $this->seedPayments($customers);

        $this->command?->info(sprintf(
            'Customer sales: %d active [%s]. Customer payments: %d [%s].',
            MilkSale::query()->active()->fromSource(SaleSource::CustomerDailyGrid)->count(),
            $sales,
            // Scoped to direct customers: Phase 5 seeds receipts from Mandalis and
            // vendors into the same table, and this line claims to count customers.
            BuyerPayment::query()->active()
                ->whereHas('buyer', fn ($q) => $q->directCustomers())
                ->count(),
            $payments,
        ));
    }

    /**
     * Two full days of deliveries, through `SaveCustomerDailySale`.
     *
     * @param  Collection<string, Buyer>  $customers
     * @return string a short summary of what was written
     */
    private function seedDeliveries($customers, int $farmId): string
    {
        $action = app(SaveCustomerDailySale::class);
        $written = 0;

        foreach ($this->dates() as $date) {
            foreach ($this->round() as $name => $byType) {
                $customer = $customers->get($name);

                if ($customer === null) {
                    continue;
                }

                foreach ($byType as $milkType => $shifts) {
                    foreach ($shifts as $shift => $quantity) {
                        $written += $this->saveCell(
                            $action, $customer, $date, Shift::from($shift), MilkType::from($milkType), $quantity, $farmId
                        );
                    }
                }
            }
        }

        $litres = Quantity::sum(
            MilkSale::query()->active()->fromSource(SaleSource::CustomerDailyGrid)->pluck('quantity')
        );

        return sprintf('%d written this run, %s L recorded', $written, $litres);
    }

    /**
     * Records one cell unless it already holds exactly that quantity.
     *
     * The skip is what makes re-seeding a no-op. Calling the action regardless would
     * not duplicate the sale — the grid identity forbids that — but it would write an
     * audit record saying a delivery changed when it did not.
     *
     * @return int 1 if something was written, 0 if it was already right
     */
    private function saveCell(
        SaveCustomerDailySale $action,
        Buyer $customer,
        string $date,
        Shift $shift,
        MilkType $milkType,
        string $quantity,
        int $farmId,
    ): int {
        $existing = MilkSale::query()
            ->active()
            ->dailyGridRow($farmId, $customer->getKey(), $date, $shift, $milkType)
            ->first();

        if ($existing !== null && Quantity::compare($existing->quantity, $quantity) === 0) {
            return 0;
        }

        $action->handle(
            customer: $customer,
            date: $date,
            shift: $shift,
            milkType: $milkType,
            quantity: $quantity,
            farmId: $farmId,
        );

        return 1;
    }

    /**
     * A few receipts against real outstanding balances.
     *
     * One partial and one in full, through two different accounts and two different
     * methods, so the cashbook has something in it and the customer list shows both a
     * settled customer and one still owing. A third customer is deliberately left
     * unpaid.
     *
     * No balance is ever written anywhere: outstanding stays derived, and these
     * payments move it by existing.
     *
     * @param  Collection<string, Buyer>  $customers
     */
    private function seedPayments($customers): string
    {
        $action = app(RecordBuyerPayment::class);
        $outstanding = app(BuyerOutstandingService::class);

        $cash = FinancialAccount::query()->where('name', 'Cash')->first();
        $bank = FinancialAccount::query()->where('name', 'Main Bank Account')->first();
        $cashMethod = PaymentMethod::query()->where('code', PaymentMethod::CASH)->first();
        $upi = PaymentMethod::query()->where('code', PaymentMethod::UPI)->first();

        if (! $cash || ! $bank || ! $cashMethod || ! $upi) {
            $this->command?->warn('Customer payments skipped: accounts or payment methods are not seeded.');

            return 'skipped';
        }

        $written = 0;

        // A part payment in cash, leaving a balance on the account.
        $written += $this->recordPayment(
            $action, $customers->get('Rajesh Patel'), 'part-1', '200.00', $cash->getKey(), $cashMethod->getKey()
        );

        /*
         * A customer settling in full. The amount is read from the derived balance
         * rather than hard-coded, so this stays exactly correct if the delivery
         * figures above are ever adjusted — and it proves the "exactly the
         * outstanding is allowed" boundary with real data.
         */
        $harish = $customers->get('Harish Trivedi');

        if ($harish !== null) {
            $due = $outstanding->breakdownFor($harish)['outstanding'];

            if (bccomp($due, '0.00', 2) > 0) {
                $written += $this->recordPayment(
                    $action, $harish, 'full-1', $due, $bank->getKey(), $upi->getKey()
                );
            }
        }

        return sprintf('%d written this run', $written);
    }

    /** Records one demo receipt unless its reference is already on the books. */
    private function recordPayment(
        RecordBuyerPayment $action,
        ?Buyer $customer,
        string $key,
        string $amount,
        int $accountId,
        int $methodId,
    ): int {
        if ($customer === null) {
            return 0;
        }

        $reference = self::PAYMENT_REFERENCE_PREFIX.$key;

        if (BuyerPayment::query()->where('reference', $reference)->exists()) {
            return 0;
        }

        $action->handle($customer, [
            'payment_date' => $this->dates()[1],
            'amount' => $amount,
            'financial_account_id' => $accountId,
            'payment_method_id' => $methodId,
            'reference' => $reference,
            'notes' => 'Development demo receipt',
        ]);

        return 1;
    }

    /**
     * The two dates the Phase 3 seeder recorded both shifts for.
     *
     * Four and three days back. Yesterday is avoided because its evening shift is
     * deliberately left unentered, and today is left empty for the developer to fill
     * in themselves. Both are outside every seeded pause.
     *
     * @return array<int, string>
     */
    private function dates(): array
    {
        return [
            now()->subDays(4)->toDateString(),
            now()->subDays(3)->toDateString(),
        ];
    }

    /**
     * The demo round: customer, milk type, shift, litres.
     *
     * Comfortably inside the seeded production on both dates, including the day that
     * also carries internal usage. Every quantity differs from that customer's
     * reminder.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private function round(): array
    {
        $cow = MilkType::Cow->value;
        $buffalo = MilkType::Buffalo->value;
        $m = Shift::Morning->value;
        $e = Shift::Evening->value;

        return [
            // Cow, at the business default rate.
            'Rajesh Patel' => [$cow => [$m => '1.250', $e => '1.750']],
            'Meena Shah' => [$cow => [$m => '0.750', $e => '0.250']],
            'Kiran Desai' => [$cow => [$m => '2.000', $e => '1.000']],
            // Morning only, which is why the evening is absent rather than zero.
            'Ashok Mehta' => [$cow => [$m => '1.500']],
            'Sunita Joshi' => [$cow => [$m => '1.250', $e => '0.750']],

            // Buffalo. Nilesh is on an agreed rate, so the two buffalo customers
            // price differently from each other.
            'Harish Trivedi' => [$buffalo => [$m => '1.250', $e => '0.750']],
            'Bhavna Solanki' => [$buffalo => [$m => '0.500', $e => '1.000']],
            'Nilesh Chauhan' => [$buffalo => [$m => '1.500', $e => '2.250']],

            // Both milk types on one customer, which is two grid rows.
            'Dinesh Rana' => [
                $cow => [$m => '0.750', $e => '0.500'],
                $buffalo => [$m => '0.750', $e => '0.250'],
            ],

            // A bulk buyer on a negotiated cow rate.
            'Hetal Dave' => [$cow => [$m => '2.500', $e => '2.500']],
        ];
    }
}
