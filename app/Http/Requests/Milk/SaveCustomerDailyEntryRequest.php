<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use App\Actions\Milk\SaveCustomerDailyDeliveries;
use App\Enums\MilkType;
use App\Enums\Shift;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Customer Daily Entry bulk save.
 *
 * The body is JSON rather than form fields, which is a deliberate architectural
 * choice (docs/DECISIONS.md D5): a two-hundred-customer day is well over a thousand
 * nested form variables, and PHP's `max_input_vars` silently truncates past its
 * limit — the request succeeds, the operator sees a success message, and the tail of
 * the day is simply missing. A JSON body is parsed as one value and cannot be
 * quietly cut in half.
 *
 * ## What is trusted
 *
 * Only four things per row: which customer, which milk type, and the two
 * quantities. Everything else about a sale — the farm, the sales channel, the rate,
 * the amount, the source, whether the customer is eligible, whether they are paused
 * — is resolved on the server. A posted `amount` or `rate` is not validated and
 * then overridden; it is **never read at all**, which is the only version of that
 * rule that cannot be undone by a later edit to this class.
 *
 * Authorisation is deliberately *not* decided here. A single save may create some
 * deliveries and correct others, and those need different permissions, so the check
 * belongs with the code that knows which operations the payload actually implies
 * ({@see SaveCustomerDailyDeliveries}).
 */
class SaveCustomerDailyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('milk.customer_delivery.view') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $quantity = [
            'nullable',
            'numeric',
            'gte:0',
            /*
             * Three decimals, matching DECIMAL(10,3). Without this a payload could
             * carry 1.4567 litres, MySQL would round it to 1.457 on the way in, and
             * every figure downstream would be exactly right about a quantity nobody
             * entered.
             */
            'decimal:0,3',
            'max:9999999.999',
        ];

        return [
            'date' => ['required', 'date'],

            // Present but empty is a legitimate save of a day with nothing in it.
            'rows' => ['present', 'array'],
            'rows.*.buyer_id' => ['required', 'integer', 'min:1'],
            'rows.*.milk_type' => ['required', 'string', 'in:'.implode(',', MilkType::values())],
            'rows.*.morning' => $quantity,
            'rows.*.evening' => $quantity,
        ];
    }

    /**
     * The submitted rows, normalised to the shape the action expects.
     *
     * Unknown keys are dropped rather than passed along: a payload carrying
     * `amount`, `unit_rate` or `farm_id` loses them here, so nothing downstream can
     * read a client-supplied value even by accident.
     *
     * @return array<int, array{buyer_id: int, milk_type: string, morning: string|null, evening: string|null}>
     */
    public function deliveryRows(): array
    {
        $rows = [];

        foreach ((array) $this->validated('rows') as $row) {
            $normalised = [
                'buyer_id' => (int) $row['buyer_id'],
                'milk_type' => (string) $row['milk_type'],
            ];

            foreach (Shift::cases() as $shift) {
                $value = $row[$shift->value] ?? null;

                $normalised[$shift->value] = $value === null || trim((string) $value) === ''
                    ? null
                    : (string) $value;
            }

            $rows[] = $normalised;
        }

        return $rows;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'date' => __('milk.fields.date'),
            'rows.*.buyer_id' => __('customers.fields.name'),
            'rows.*.milk_type' => __('milk.fields.milk_type'),
            'rows.*.morning' => __('milk.shifts.morning'),
            'rows.*.evening' => __('milk.shifts.evening'),
        ];
    }
}
