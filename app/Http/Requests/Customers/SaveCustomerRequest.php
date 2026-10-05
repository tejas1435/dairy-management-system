<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\MilkType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a direct customer profile and its milk preferences.
 *
 * `sales_channel_id` is deliberately **not** a rule here. The channel is assigned
 * server-side from the seeded `direct_customer` slug, so posting one has no effect —
 * and because `preventSilentlyDiscardingAttributes` is on, a stray value cannot slip
 * into a fill either. A customer's channel is changed through the generic buyer
 * master, which re-checks the destination channel's permission.
 */
class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The customer being edited is authorised by the controller through
        // BuyerPolicy, which resolves the permission from the record's channel.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $reminder = ['nullable', 'numeric', 'gte:0', 'decimal:0,3', 'max:9999999.999'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'area' => ['nullable', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:1000'],
            'payment_cycle' => ['nullable', 'string', 'max:40'],
            'start_date' => ['nullable', 'date'],

            // One block per milk type; a milk type left out is not touched.
            'preferences' => ['nullable', 'array'],
            'preferences.*.is_active' => ['nullable', 'boolean'],
            'preferences.*.morning' => $reminder,
            'preferences.*.evening' => $reminder,
        ];
    }

    /** Discards preference keys that are not real milk types. */
    protected function prepareForValidation(): void
    {
        $preferences = $this->input('preferences');

        if (! is_array($preferences)) {
            return;
        }

        $this->merge([
            'preferences' => array_intersect_key($preferences, array_flip(MilkType::values())),
        ]);
    }

    /**
     * The preference rows, normalised for the action.
     *
     * @return array<string, array{is_active: bool, morning: string, evening: string}>
     */
    public function preferenceRows(): array
    {
        $rows = [];

        foreach ((array) ($this->validated('preferences') ?? []) as $milkType => $row) {
            $rows[(string) $milkType] = [
                'is_active' => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'morning' => (string) ($row['morning'] ?? '0'),
                'evening' => (string) ($row['evening'] ?? '0'),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = [
            'name' => __('customers.fields.name'),
            'mobile' => __('customers.fields.mobile'),
            'area' => __('customers.fields.area'),
            'delivery_note' => __('customers.fields.delivery_note'),
            'payment_cycle' => __('customers.fields.payment_cycle'),
            'start_date' => __('customers.fields.start_date'),
        ];

        foreach (MilkType::cases() as $milkType) {
            $attributes["preferences.{$milkType->value}.morning"] =
                $milkType->label().' — '.__('customers.fields.morning_reminder');
            $attributes["preferences.{$milkType->value}.evening"] =
                $milkType->label().' — '.__('customers.fields.evening_reminder');
        }

        return $attributes;
    }
}
