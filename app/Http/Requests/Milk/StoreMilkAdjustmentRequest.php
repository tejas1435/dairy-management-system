<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use App\Enums\AdjustmentDirection;
use App\Enums\MilkType;
use App\Enums\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an authorised milk adjustment.
 *
 * The reason is `required` with a real minimum length. A one-character reason
 * satisfies "not empty" while explaining nothing, and the reason is the only thing
 * that distinguishes a deliberate correction from a mistake when somebody reads
 * this row back in six months.
 *
 * The quantity is always positive; `direction` carries the sign
 * (docs/DECISIONS.md D31).
 */
class StoreMilkAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('milk.adjustment.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'adjustment_date' => ['required', 'date'],
            'shift' => ['required', Rule::in(Shift::values())],
            'milk_type' => ['required', Rule::in(MilkType::values())],
            'direction' => ['required', Rule::in(AdjustmentDirection::values())],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999.999'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'adjustment_date' => __('milk.fields.date'),
            'shift' => __('milk.fields.shift'),
            'milk_type' => __('milk.fields.milk_type'),
            'direction' => __('milk.fields.direction'),
            'quantity' => __('milk.fields.quantity'),
            'reason' => __('milk.fields.reason'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => __('milk.errors.adjustment_reason_required'),
            'reason.min' => __('milk.errors.adjustment_reason_too_short'),
        ];
    }
}
