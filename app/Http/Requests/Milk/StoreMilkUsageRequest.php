<?php

declare(strict_types=1);

namespace App\Http\Requests\Milk;

use App\Enums\MilkType;
use App\Enums\MilkUsageType;
use App\Enums\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates one internal usage entry.
 *
 * Shape only. Whether there is enough milk to allocate is decided by
 * MilkAvailability inside the action's transaction, not here: a Form Request runs
 * before the transaction opens, so a check made at this point could be true when
 * it is asked and false by the time the row is written.
 */
class StoreMilkUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('milk.usage.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'usage_date' => ['required', 'date'],
            'shift' => ['required', Rule::in(Shift::values())],
            'milk_type' => ['required', Rule::in(MilkType::values())],
            'usage_type' => ['required', Rule::in(MilkUsageType::values())],
            // gt:0 here, unlike production: a usage row recording zero litres
            // records nothing that happened.
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999.999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'usage_date' => __('milk.fields.date'),
            'shift' => __('milk.fields.shift'),
            'milk_type' => __('milk.fields.milk_type'),
            'usage_type' => __('milk.fields.usage_type'),
            'quantity' => __('milk.fields.quantity'),
            'notes' => __('milk.fields.notes'),
        ];
    }
}
