<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use App\Services\Milk\MilkSaleSlips;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a correction to a recorded Mandali, vendor or generic sale.
 *
 * Narrower than the create form on purpose. The buyer, the date, the shift and the
 * milk type are **not** editable: changing any of them would move the sale to a
 * different reconciliation unit or a different account, which is a different sale.
 * Correcting those means withdrawing this one and recording the right one, which
 * leaves both in the history.
 *
 * What can be corrected is what somebody plausibly mistyped: the quantity, the
 * quality readings, the notes, the slip — and the rate, which needs
 * `milk.sale.override_rate` and a reason, enforced in the action.
 */
class UpdateChannelSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('milk.sale.update') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999.999'],

            // Optional: left empty, the stored snapshot stands (D41).
            'unit_rate' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
            'rate_override_reason' => ['nullable', 'string', 'max:500'],

            'fat_percentage' => [
                'nullable', 'numeric', 'gte:0', 'decimal:0,2',
                'max:'.StoreChannelSaleRequest::QUALITY_MAX,
            ],
            'snf_percentage' => [
                'nullable', 'numeric', 'gte:0', 'decimal:0,2',
                'max:'.StoreChannelSaleRequest::QUALITY_MAX,
            ],

            'notes' => ['nullable', 'string', 'max:1000'],

            'slip' => array_merge(['nullable'], MilkSaleSlips::rules()),
            'remove_slip' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The changes the action applies.
     *
     * Quality keys are included only when they were submitted, so a form that does
     * not render them — a vendor sale, which has no readings — cannot blank out
     * values it never showed.
     *
     * @return array<string, mixed>
     */
    public function saleChanges(): array
    {
        $changes = [
            'quantity' => $this->string('quantity')->toString(),
            'notes' => $this->input('notes'),
            'slip' => $this->file('slip'),
            'remove_slip' => $this->boolean('remove_slip'),
        ];

        if ($this->filled('unit_rate')) {
            $changes['unit_rate'] = $this->input('unit_rate');
            $changes['rate_override_reason'] = $this->input('rate_override_reason');
        }

        foreach (['fat_percentage', 'snf_percentage'] as $reading) {
            if ($this->has($reading)) {
                $changes[$reading] = $this->input($reading);
            }
        }

        return $changes;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'quantity' => __('buyers.sale.fields.quantity'),
            'unit_rate' => __('buyers.sale.fields.rate'),
            'fat_percentage' => __('buyers.sale.fields.fat'),
            'snf_percentage' => __('buyers.sale.fields.snf'),
            'rate_override_reason' => __('buyers.sale.fields.override_reason'),
            'slip' => __('buyers.sale.fields.slip'),
        ];
    }
}
