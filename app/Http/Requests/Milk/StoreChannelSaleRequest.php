<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use App\Enums\MilkType;
use App\Enums\SaleSource;
use App\Enums\Shift;
use App\Services\Milk\MilkSaleSlips;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a Mandali, vendor or generic sale.
 *
 * ## The fat and SNF bounds
 *
 * MASTER_SPEC requires fat and SNF to be recorded but does not state a valid range,
 * so one is chosen here and documented rather than left open:
 *
 * | Reading | Range | Why |
 * | ------- | ----- | --- |
 * | Fat | 0.00 – 15.00 | Cow milk runs about 3–5% and buffalo about 6–8%. 15 is far above any real reading while still accepting cream-rich buffalo milk and a mis-set meter the operator wants to record as measured. |
 * | SNF | 0.00 – 15.00 | Normally 8–9.5%. Same reasoning. |
 *
 * The bounds are deliberately loose. A range tight enough to catch every typo would
 * also reject legitimate outliers, and the cost of the two errors is not symmetrical:
 * a refused real reading blocks the day's work, while an implausible stored one is
 * visible on screen and corrigible. `DECIMAL(5,2)` is the hard limit either way.
 *
 * **Fat is required for a Mandali collection** — it is the headline figure on the
 * dairy's own slip, and a collection recorded without it cannot be checked against
 * that slip later. SNF is optional, because not every collection point measures it.
 * Neither affects the rate or the amount.
 */
class StoreChannelSaleRequest extends FormRequest
{
    public const QUALITY_MAX = '15.00';

    public function authorize(): bool
    {
        return $this->user()?->can('milk.sale.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $source = $this->saleSource();

        return [
            'buyer_id' => ['required', 'integer', 'min:1'],
            'sale_date' => ['required', 'date'],
            'shift' => ['required', Rule::in(Shift::values())],
            'milk_type' => ['required', Rule::in(MilkType::values())],

            'quantity' => [
                'required',
                'numeric',
                'gt:0',
                // Three decimals, matching DECIMAL(10,3). Rejected rather than
                // rounded, so no figure is stored that nobody entered.
                'decimal:0,3',
                'max:9999999.999',
            ],

            /*
             * Required where the workflow sets its own rate. For a vendor it may be
             * left empty to take the configured rate — and a different figure is an
             * override, which the action authorises.
             */
            'unit_rate' => [
                $source->usesManualRate() ? 'required' : 'nullable',
                'numeric',
                'gt:0',
                'decimal:0,2',
                'max:99999999.99',
            ],

            'fat_percentage' => [
                $source->recordsMilkQuality() ? 'required' : 'nullable',
                'numeric',
                'gte:0',
                'decimal:0,2',
                'max:'.self::QUALITY_MAX,
            ],
            'snf_percentage' => [
                'nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:'.self::QUALITY_MAX,
            ],

            // Required by the action when the rate is an override; accepted here
            // whenever it is given.
            'rate_override_reason' => ['nullable', 'string', 'max:500'],

            'notes' => ['nullable', 'string', 'max:1000'],

            'slip' => $source->recordsMilkQuality()
                ? array_merge(['nullable'], MilkSaleSlips::rules())
                : ['prohibited'],
        ];
    }

    /**
     * The extra attributes the action takes.
     *
     * Only the fields this workflow owns. Nothing else from the request is passed
     * through, so a posted `amount`, `farm_id` or `source` cannot reach the sale even
     * by accident.
     *
     * @return array<string, mixed>
     */
    public function saleAttributes(): array
    {
        return [
            'fat_percentage' => $this->input('fat_percentage'),
            'snf_percentage' => $this->input('snf_percentage'),
            'rate_override_reason' => $this->input('rate_override_reason'),
            'notes' => $this->input('notes'),
            'slip' => $this->file('slip'),
        ];
    }

    /** The workflow this form belongs to, from the route rather than the payload. */
    public function saleSource(): SaleSource
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_contains($name, 'mandali-deliveries') => SaleSource::MandaliDelivery,
            str_contains($name, 'vendor-sales') => SaleSource::VendorSale,
            default => SaleSource::GenericSale,
        };
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'buyer_id' => __('buyers.sale.fields.buyer'),
            'sale_date' => __('buyers.sale.fields.date'),
            'shift' => __('buyers.sale.fields.shift'),
            'milk_type' => __('buyers.sale.fields.milk_type'),
            'quantity' => __('buyers.sale.fields.quantity'),
            'unit_rate' => __('buyers.sale.fields.rate'),
            'fat_percentage' => __('buyers.sale.fields.fat'),
            'snf_percentage' => __('buyers.sale.fields.snf'),
            'rate_override_reason' => __('buyers.sale.fields.override_reason'),
            'slip' => __('buyers.sale.fields.slip'),
        ];
    }
}
